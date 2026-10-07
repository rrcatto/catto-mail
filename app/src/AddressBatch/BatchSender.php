<?php

declare(strict_types=1);

namespace App\AddressBatch;

use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Client\ClientLimitPolicy;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\SendJob;
use App\Entity\User;
use App\Enum\BatchSendStage;
use App\Sending\AddressNormalizer;
use App\Sending\SendJobService;
use App\Util\Clock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Sends from an administrator address batch (specification 2.11), in rollout stages:
 *
 *   seed        a handful of addresses the administrator owns (typed in, never from the list)
 *   controlled  a small sample of the eligible addresses
 *   rollout     a further portion, while bounces, complaints and deferrals are watched
 *   full        every remaining eligible address
 *
 * Only eligible addresses are ever selected (valid or included after review, not
 * suppressed, not unsubscribed), each at most once per batch. Anything but a seed test
 * needs the batch's compliance approval (the live-sending compliance gate) and its List-Id.
 * The send goes through the ordinary send-job service as the batch's client, so the
 * client's sending-domain rules, quotas and limits apply, and the delivery daemon checks
 * every suppression again before each submission (that check stays authoritative). In held
 * mode the job simply waits: nothing reaches the Internet until live delivery is activated.
 *
 * Content: a subject and a plain-text body (and optionally HTML) with placeholders. Every
 * message carries the recipient's own re-permission links; for a seed test they point at a
 * demonstration page.
 */
final class BatchSender
{
    public const MAX_SEED = 20;
    public const PLACEHOLDERS = ['{{response_url}}', '{{confirm_url}}', '{{unsubscribe_url}}', '{{global_opt_out_url}}', '{{address}}'];
    private const CHUNK = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly SendJobService $sendJobs,
        private readonly ClientLimitPolicy $limits,
        private readonly RepermissionService $repermission,
        private readonly BatchReadModel $read,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * What a stage would send.
     *
     * @param array{stage: string, count?: int|string, seed_addresses?: string} $form
     *
     * @return array{stage: string, recipients: list<array{id: ?string, address: string}>, summary: array<string, int>, blockers: list<string>}
     */
    public function preview(array $batch, Client $client, array $form): array
    {
        $stage = BatchSendStage::tryFrom((string) ($form['stage'] ?? '')) ?? throw new DomainRuleViolation('Choose a stage.');
        $summary = $this->read->summary((string) $batch['id']);
        $blockers = [];
        if (!$client->mayCreateWork()) {
            $blockers[] = 'The client is '.$client->getStatus()->value.': it cannot send.';
        }
        if (BatchSendStage::Seed === $stage) {
            $recipients = [];
            foreach (preg_split('/[\s,;]+/', (string) ($form['seed_addresses'] ?? ''), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $a) {
                $n = AddressNormalizer::normalize($a);
                if (null === $n || !AddressNormalizer::isDeliverableSyntax($n)) {
                    $blockers[] = "\"$a\" is not a usable address.";
                    continue;
                }
                $recipients[$n] = ['id' => null, 'address' => $n];
            }
            $recipients = array_values($recipients);
            if ([] === $recipients) {
                $blockers[] = 'Enter one or more addresses that you own.';
            }
            if (\count($recipients) > self::MAX_SEED) {
                $blockers[] = 'A seed test is for a handful of your own addresses (at most '.self::MAX_SEED.').';
            }
        } else {
            if (null === $batch['compliance_approved_at']) {
                $blockers[] = 'The batch has no compliance approval yet: record who approved sending to this list and on what basis.';
            }
            if (null === $batch['list_id']) {
                $blockers[] = 'Set the list identifier (List-Id) first.';
            }
            $available = $summary['unsent_eligible'] ?? 0;
            $count = BatchSendStage::Full === $stage ? $available : (int) ($form['count'] ?? 0);
            if ($count < 1) {
                $blockers[] = BatchSendStage::Full === $stage ? 'No eligible address is left to send to.' : 'Choose how many addresses to send to.';
            }
            if ($count > $available) {
                $blockers[] = "Only $available eligible addresses have not been sent to yet.";
            }
            $max = $this->limits->maxRecipientsPerSendJob($client);
            if ($count > $max) {
                $blockers[] = "One send is limited to $max recipients for this client: send in rollout stages.";
            }
            $recipients = $count < 1 ? [] : array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'address' => (string) $r['normalized_address']],
                $this->connection->fetchAllAssociative('SELECT id::text AS id, normalized_address FROM ('.EntryStates::sql().") s
                    WHERE eligibility = 'eligible' AND delivery_state = 'not_sent' ORDER BY md5(id::text) LIMIT :n",
                    ['batch' => $batch['id'], 'n' => min($count, $max, $available)], ['n' => \Doctrine\DBAL\ParameterType::INTEGER]));
        }

        return ['stage' => $stage->value, 'recipients' => $recipients, 'summary' => $summary, 'blockers' => $blockers];
    }

    /**
     * Creates, fills and submits the send job.
     *
     * @param array{stage: string, count?: int|string, seed_addresses?: string, subject: string, text_body: string, html_body?: string,
     *              sender_email: string, sender_name?: string, track_opens?: bool, track_clicks?: bool, i_own_these?: bool} $form
     */
    public function send(array $batch, Client $client, array $form, User $user): string
    {
        $preview = $this->preview($batch, $client, $form);
        if ([] !== $preview['blockers']) {
            throw new DomainRuleViolation(implode(' ', $preview['blockers']));
        }
        $seed = 'seed' === $preview['stage'];
        if ($seed && true !== ($form['i_own_these'] ?? false)) {
            throw new DomainRuleViolation('Confirm that you own every seed address: seed tests go only to addresses you control.');
        }
        [$subject, $text, $html] = self::content($form, 'repermission' === $batch['purpose'] && !$seed);
        $batchId = (string) $batch['id'];
        $stamp = Uuid::v7()->toRfc4122();

        return $this->connection->transactional(function () use ($batch, $client, $form, $user, $preview, $seed, $subject, $text, $html, $batchId, $stamp): string {
            $key = IdempotencyKey::internal("address-batch-send-$stamp");
            $data = ['external_reference' => mb_substr("address-batch:$batchId:{$preview['stage']}", 0, 255),
                // RFC 2919: the List-Id header value is the identifier in angle brackets.
                'message_class' => 'subscription', 'list_id' => '<'.($seed ? ($batch['list_id'] ?? 'seed-test.invalid') : (string) $batch['list_id']).'>',
                'sender_identity' => array_filter(['email' => trim((string) $form['sender_email']), 'name' => trim((string) ($form['sender_name'] ?? '')) ?: null]),
                'tracking' => ['opens' => (bool) ($form['track_opens'] ?? false), 'clicks' => (bool) ($form['track_clicks'] ?? false)]];
            try {
                [$job] = $this->sendJobs->create($client, $key, hash('sha256', json_encode($data, \JSON_THROW_ON_ERROR)), $data);
            } catch (ApiProblem $p) {
                throw new DomainRuleViolation(self::problemText($p));
            }
            $n = 0;
            foreach (array_chunk($preview['recipients'], self::CHUNK) as $i => $chunk) {
                $recipients = [];
                foreach ($chunk as $r) {
                    $urls = self::urls($seed ? $this->repermission->url('seed-test') : $this->repermission->issue((string) $r['id']));
                    $recipients[] = array_filter([
                        'external_recipient_reference' => $seed ? 'seed:'.(++$n) : 'entry:'.$r['id'],
                        'email_address' => $r['address'],
                        'subject' => self::render($subject, $urls, $r['address'], false),
                        'text_body' => self::render($text, $urls, $r['address'], false),
                        'html_body' => null === $html ? null : self::render($html, $urls, $r['address'], true),
                        'unsubscribe_url' => $urls['{{unsubscribe_url}}'],
                    ], static fn ($v) => null !== $v);
                }
                $bk = IdempotencyKey::internal("address-batch-send-$stamp-$i");
                try {
                    $this->sendJobs->addBatch($job, $bk, hash('sha256', $bk->value), ['recipients' => $recipients]);
                } catch (ApiProblem $p) {
                    throw new DomainRuleViolation(self::problemText($p));
                }
            }
            $job = $this->sendJobs->submit($this->em->find(SendJob::class, $job->getId()) ?? $job);
            $this->connection->insert('address_batch_sends', [
                'id' => Uuid::v7()->toRfc4122(), 'batch_id' => $batchId, 'send_job_id' => $job->getId()->toRfc4122(),
                'stage' => $preview['stage'], 'recipient_count' => \count($preview['recipients']), 'subject' => mb_substr($subject, 0, 998),
                'created_by_user_id' => $user->getId()->toRfc4122(), 'created_at' => Clock::now()->format('Y-m-d H:i:s.uP'),
            ]);
            $this->audit->record(AuditActor::user($user), 'address_batch.send_created', 'address_batch', $batchId, [
                'stage' => $preview['stage'], 'send_job_id' => $job->getId()->toRfc4122(), 'recipients' => \count($preview['recipients'])]);

            return $job->getId()->toRfc4122();
        });
    }

    /** @return array{0: string, 1: string, 2: ?string} subject, text, html */
    public static function content(array $form, bool $repermission): array
    {
        $subject = trim((string) ($form['subject'] ?? ''));
        $text = trim((string) ($form['text_body'] ?? ''));
        $html = trim((string) ($form['html_body'] ?? ''));
        if ('' === $subject || mb_strlen($subject) > 998) {
            throw new DomainRuleViolation('Give a subject (at most 998 characters).');
        }
        if ('' === $text) {
            throw new DomainRuleViolation('Give the plain-text body (every message has one; HTML is optional).');
        }
        if ($repermission && !str_contains($text, '{{response_url}}') && !str_contains($text, '{{confirm_url}}')) {
            throw new DomainRuleViolation('A re-permission message must contain {{response_url}} (or {{confirm_url}}) so the recipient can answer.');
        }
        if ('' !== $html && 1 === preg_match('/<script|on[a-z]+\s*=|javascript:/i', $html)) {
            throw new DomainRuleViolation('The HTML body may not contain scripts or event handlers.');
        }

        return [$subject, $text, '' === $html ? null : $html];
    }

    /** @return array<string, string> */
    private static function urls(string $page): array
    {
        return ['{{response_url}}' => $page, '{{confirm_url}}' => $page.'?choice=confirm', '{{unsubscribe_url}}' => $page.'?choice=unsubscribe',
            '{{global_opt_out_url}}' => $page.'?choice=global_opt_out'];
    }

    /** @param array<string, string> $urls */
    public static function render(string $template, array $urls, string $address, bool $html): string
    {
        $values = $urls + ['{{address}}' => $address];
        if ($html) {
            $values = array_map(static fn (string $v): string => htmlspecialchars($v, \ENT_QUOTES | \ENT_HTML5), $values);
        }

        return strtr($template, $values);
    }

    private static function problemText(ApiProblem $p): string
    {
        $errors = array_map(static fn (array $e): string => (string) ($e['message'] ?? ''), $p->errors);

        return trim(($p->detail ?? $p->title).' '.implode(' ', array_filter($errors)));
    }
}
