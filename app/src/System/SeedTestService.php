<?php

declare(strict_types=1);

namespace App\System;

use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\SendJob;
use App\Entity\User;
use App\Sending\AddressNormalizer;
use App\Sending\SendJobService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The guided end-to-end tests of the setup wizard (specification 2.11): a seed test to a
 * few addresses the administrator owns, and a bounce test to a mailbox that does not exist
 * at a domain the administrator controls. They are ordinary send jobs of a client with a
 * verified, DKIM-signing sending domain, with open and click tracking on, so every stage
 * of the real path is exercised. Stages are judged only from what the system recorded;
 * what it cannot observe (DKIM verification and inbox placement at the receiver) is
 * confirmed by the administrator from the received message.
 */
final class SeedTestService
{
    public const MAX_ADDRESSES = 5;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly SendJobService $sendJobs,
        private readonly SystemState $state,
        private readonly AuditLogger $audit,
        #[Autowire('%env(SMARTHOST_PUBLIC_BASE_URL)%')] private readonly string $publicBaseUrl,
    ) {
    }

    /** @param list<string> $addresses */
    public function start(string $kind, Client $client, string $senderEmail, array $addresses, bool $owned, User $user): string
    {
        if (!\in_array($kind, ['seed', 'bounce'], true)) {
            throw new DomainRuleViolation('Unknown test.');
        }
        if (!$owned) {
            throw new DomainRuleViolation('Confirm that you own or control every address: tests go only to addresses you control.');
        }
        $clean = [];
        foreach ($addresses as $a) {
            $n = AddressNormalizer::normalize(trim($a));
            if (null === $n || !AddressNormalizer::isDeliverableSyntax($n)) {
                throw new DomainRuleViolation("\"$a\" is not a usable address.");
            }
            $clean[$n] = $n;
        }
        if ([] === $clean || \count($clean) > self::MAX_ADDRESSES) {
            throw new DomainRuleViolation('Enter 1 to '.self::MAX_ADDRESSES.' addresses.');
        }
        $stamp = Uuid::v7()->toRfc4122();
        $site = rtrim($this->publicBaseUrl, '/');
        $data = ['external_reference' => "catto-$kind-test-$stamp", 'message_class' => 'transactional',
            'sender_identity' => ['email' => trim($senderEmail), 'name' => 'catto-mail system test'],
            'tracking' => ['opens' => true, 'clicks' => true]];
        $key = IdempotencyKey::internal("system-$kind-test-$stamp");
        try {
            $jobId = $this->connection->transactional(function () use ($client, $key, $data, $clean, $kind, $site, $stamp): string {
                [$job] = $this->sendJobs->create($client, $key, hash('sha256', $key->value), $data);
                $recipients = [];
                $i = 0;
                foreach ($clean as $address) {
                    $recipients[] = ['external_recipient_reference' => "$kind-test-".(++$i), 'email_address' => $address,
                        'subject' => 'bounce' === $kind ? 'catto-mail bounce test (this address should not exist)' : 'catto-mail seed delivery test',
                        'text_body' => "This is a catto-mail $kind test sent by your administrator.\n\nTest id: $stamp\nOpen this link to test click tracking: $site/healthz\n\nNo action is needed.",
                        'html_body' => '<p>This is a catto-mail '.$kind.' test sent by your administrator.</p><p>Test id: '.$stamp
                            .'</p><p><a href="'.htmlspecialchars($site.'/healthz', \ENT_QUOTES).'">Open this link to test click tracking</a></p><p>No action is needed.</p>'];
                }
                $bk = IdempotencyKey::internal("system-$kind-test-$stamp-batch");
                $this->sendJobs->addBatch($job, $bk, hash('sha256', $bk->value), ['recipients' => $recipients]);
                $this->sendJobs->submit($this->em->find(SendJob::class, $job->getId()) ?? $job);

                return $job->getId()->toRfc4122();
            });
        } catch (ApiProblem $p) {
            throw new DomainRuleViolation(trim(($p->detail ?? $p->title).' '.implode(' ', array_map(static fn (array $e): string => (string) ($e['message'] ?? ''), $p->errors))));
        }
        $tests = $this->tests();
        array_unshift($tests, ['job_id' => $jobId, 'kind' => $kind, 'client_id' => $client->getId()->toRfc4122(), 'started_at' => date('c'),
            'started_by' => $user->getEmail(), 'confirmed' => []]);
        $this->state->put(SystemState::SEED_TESTS, ['tests' => \array_slice($tests, 0, 20)]);
        $this->audit->record(AuditActor::user($user), 'system.'.$kind.'_test_started', 'send_job', $jobId, ['recipients' => \count($clean)]);

        return $jobId;
    }

    /** @return list<array<string, mixed>> */
    public function tests(): array
    {
        return array_values($this->state->get(SystemState::SEED_TESTS)['value']['tests'] ?? []);
    }

    public function confirm(string $jobId, string $what, User $user): void
    {
        if (!\in_array($what, ['dkim_pass', 'inbox_seen', 'spf_pass', 'dmarc_pass'], true)) {
            throw new DomainRuleViolation('Unknown confirmation.');
        }
        $tests = $this->tests();
        foreach ($tests as &$t) {
            if ($t['job_id'] === $jobId) {
                $t['confirmed'][$what] = ['by' => $user->getEmail(), 'at' => date('c')];
            }
        }
        unset($t);
        $this->state->put(SystemState::SEED_TESTS, ['tests' => $tests]);
        $this->audit->record(AuditActor::user($user), 'system.test_confirmed', 'send_job', $jobId, ['confirmed' => $what]);
    }

    /**
     * Every stage of a test, from recorded evidence only.
     *
     * @return array{test: array<string, mixed>, stages: list<array{stage: string, result: string, detail: string}>, verdict: string}
     */
    public function report(string $jobId): array
    {
        $test = null;
        foreach ($this->tests() as $t) {
            if ($t['job_id'] === $jobId) {
                $test = $t;
            }
        }
        if (null === $test || !Uuid::isValid($jobId)) {
            throw new DomainRuleViolation('No such test.');
        }
        $job = $this->connection->fetchAssociative('SELECT status, created_at, queued_at, started_at, total_recipients, client_id::text AS client_id FROM send_jobs WHERE id = ?', [$jobId]);
        $ev = static fn (array $types): string => "(SELECT count(DISTINCT m.id) FROM messages m JOIN message_events e ON e.message_id = m.id WHERE m.send_job_id = :job AND e.event_type IN ('".implode("','", $types)."'))";
        $c = $this->connection->fetchAssociative('SELECT (SELECT count(*) FROM messages WHERE send_job_id = :job) AS messages, '
            .$ev(['submitted_to_postfix']).' AS submitted, '.$ev(['remote_accepted']).' AS accepted, '.$ev(['hard_bounce']).' AS hard, '
            .$ev(['soft_bounce', 'deferred']).' AS temp, '.$ev(['open_recorded']).' AS opens, '.$ev(['click_recorded']).' AS clicks, '
            ."(SELECT count(*) FROM suppressions s JOIN messages m ON m.id = s.source_message_id WHERE m.send_job_id = :job AND s.reason = 'hard_bounce') AS suppressed, "
            ."(SELECT count(*) FROM webhook_events w WHERE w.client_id = CAST(:client AS uuid) AND w.created_at >= CAST(:since AS timestamptz)) AS webhooks",
            ['job' => $jobId, 'client' => $job['client_id'] ?? $test['client_id'], 'since' => $job['created_at'] ?? $test['started_at']]) ?: [];
        $n = (int) ($job['total_recipients'] ?? 0);
        $s = static fn (bool $ok, bool $waiting = true): string => $ok ? 'pass' : ($waiting ? 'waiting' : 'fail');
        $confirmed = $test['confirmed'] ?? [];
        $stages = [
            ['stage' => 'Job created', 'result' => $s(false !== $job), 'detail' => false === $job ? 'missing' : 'status '.$job['status']],
            ['stage' => 'Recipients accepted by catto-mail', 'result' => $s($n > 0), 'detail' => "$n recipient(s)"],
            ['stage' => 'Claimed by the delivery daemon', 'result' => $s(null !== ($job['started_at'] ?? null)),
                'detail' => null === ($job['started_at'] ?? null) ? 'Not yet: in HELD mode send jobs wait until live delivery is activated.' : 'at '.$job['started_at']],
            ['stage' => 'Message created', 'result' => $s((int) $c['messages'] === $n && $n > 0), 'detail' => $c['messages'].' of '.$n],
            ['stage' => 'Submitted to Postfix', 'result' => $s((int) $c['submitted'] > 0), 'detail' => $c['submitted'].' message(s)'],
            ['stage' => 'DKIM signed (seen in the received message)', 'result' => isset($confirmed['dkim_pass']) ? 'pass' : 'manual',
                'detail' => 'Postfix passes every message through OpenDKIM; catto-mail cannot see the result. Open the received message and look for dkim=pass in Authentication-Results.'],
        ];
        if ('bounce' === $test['kind']) {
            $stages[] = ['stage' => 'Remote MX rejected the address (hard bounce)', 'result' => $s((int) $c['hard'] > 0), 'detail' => $c['hard'].' hard bounce(s); '.$c['temp'].' temporary'];
            $stages[] = ['stage' => 'Global hard-bounce suppression created', 'result' => $s((int) $c['suppressed'] > 0), 'detail' => $c['suppressed'].' suppression(s)'];
        } else {
            $stages[] = ['stage' => 'Remote MX accepted', 'result' => $s((int) $c['accepted'] > 0), 'detail' => $c['accepted'].' message(s); not proof of inbox placement'];
            $stages[] = ['stage' => 'Seen in the inbox (your observation)', 'result' => isset($confirmed['inbox_seen']) ? 'pass' : 'manual', 'detail' => 'Only you can see where it landed.'];
            $stages[] = ['stage' => 'Open recorded (tracking pixel)', 'result' => $s((int) $c['opens'] > 0), 'detail' => $c['opens'].' message(s); needs images shown in your mail client'];
            $stages[] = ['stage' => 'Click recorded (redirect)', 'result' => $s((int) $c['clicks'] > 0), 'detail' => $c['clicks'].' message(s); click the link in the message'];
        }
        $stages[] = ['stage' => 'Webhook events for the client', 'result' => (int) $c['webhooks'] > 0 ? 'pass' : 'info',
            'detail' => $c['webhooks'].' event(s) since the test started (needs an enabled endpoint subscribed to send/message events)'];
        $auto = array_filter($stages, static fn (array $x): bool => !\in_array($x['result'], ['manual', 'info'], true));
        $verdict = [] !== array_filter($auto, static fn (array $x): bool => 'fail' === $x['result']) ? 'FAIL'
            : ([] === array_filter($stages, static fn (array $x): bool => \in_array($x['result'], ['waiting', 'manual'], true)) ? 'PASS' : 'IN PROGRESS');

        return ['test' => $test, 'stages' => $stages, 'verdict' => $verdict];
    }
}
