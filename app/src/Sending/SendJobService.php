<?php

declare(strict_types=1);

namespace App\Sending;

use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\WorkPermission;
use App\Config\Limits;
use App\Entity\Client;
use App\Entity\SendingDomain;
use App\Entity\SendJob;
use App\Entity\SendJobRecipientBatch;
use App\Enum\DkimStatus;
use App\Enum\MessageClass;
use App\Enum\SendJobStatus;
use App\Idempotency\IdempotencyLock;
use App\Util\Clock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Staged send-job ingestion (D-24, spec sending.ingestion):
 *
 *   create  -> job `collecting` with job-level metadata only;
 *   batch   -> up to 500 fully rendered recipients, accepted or rejected whole;
 *   submit  -> atomically seal the recipient set, job `queued`.
 *
 * Batches and submit serialise on the job row (SELECT ... FOR UPDATE), so a
 * submit can never interleave with a batch: once sealed, no recipient can be
 * added. Nothing here creates `messages` or talks to Postfix; the Go delivery
 * daemon (Phase 4) claims `queued` jobs.
 */
final class SendJobService
{
    private const INSERT_CHUNK = 250;
    private const MAX_REPORTED_ERRORS = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly IdempotencyLock $lock,
        private readonly Limits $limits,
        private readonly string $smarthostEnv,
        private readonly bool $allowUnverifiedSendingDomains,
    ) {
    }

    /**
     * @param array<string, mixed> $data contract-valid SendJobCreateRequest
     *
     * @return array{0: SendJob, 1: bool} the job and whether this is an idempotent replay
     */
    public function create(Client $client, IdempotencyKey $key, string $requestHash, array $data): array
    {
        WorkPermission::assertMayCreateWork($client);

        return $this->em->wrapInTransaction(function () use ($client, $key, $requestHash, $data): array {
            if (!$this->lock->tryAcquire('send-jobs|'.$client->getId()->toRfc4122().'|'.$key->value)) {
                throw ApiProblem::idempotencyInProgress();
            }
            $existing = $this->em->getRepository(SendJob::class)->findOneBy(['client' => $client, 'idempotencyKey' => $key->value]);
            if (null !== $existing) {
                return hash_equals($existing->getRequestHash(), $requestHash) ? [$existing, true] : throw ApiProblem::idempotencyKeyReused();
            }

            $errors = [];
            $sender = AddressNormalizer::normalize($data['sender_identity']['email']);
            $domain = null;
            if (null === $sender || !AddressNormalizer::isDeliverableSyntax($sender)) {
                $errors[] = ['pointer' => '/sender_identity/email', 'message' => 'Not a usable sender address.'];
            } else {
                $domain = $this->em->getRepository(SendingDomain::class)->findOneBy(
                    ['client' => $client, 'domain' => AddressNormalizer::domainOf($sender)]);
                if (null === $domain || $domain->isDisabled()) {
                    $errors[] = ['pointer' => '/sender_identity/email',
                        'message' => 'The sender domain is not a registered, enabled sending domain of this client.'];
                }
            }
            $replyTo = null;
            if (isset($data['reply_to'])) {
                $replyTo = AddressNormalizer::normalize($data['reply_to']['email']);
                if (null === $replyTo || !AddressNormalizer::isDeliverableSyntax($replyTo)) {
                    $errors[] = ['pointer' => '/reply_to/email', 'message' => 'Not a usable reply-to address.'];
                }
            }
            foreach (['/list_id' => $data['list_id'] ?? null, '/sender_identity/name' => $data['sender_identity']['name'] ?? null,
                '/reply_to/name' => $data['reply_to']['name'] ?? null] as $pointer => $value) {
                if (null !== $value && self::hasControlCharacters($value)) {
                    $errors[] = ['pointer' => $pointer, 'message' => 'Control characters (including line breaks) are not allowed.'];
                }
            }
            if ([] !== $errors) {
                throw ApiProblem::unprocessable('validation-error', 'Validation failed', 'The send job cannot be created.', $errors);
            }

            $job = new SendJob($client, $data['external_reference'], $key->value, $requestHash,
                MessageClass::from($data['message_class']), $domain, $sender);
            $job->configure($data['list_id'] ?? null, $data['sender_identity']['name'] ?? null, $replyTo,
                $data['reply_to']['name'] ?? null, (bool) ($data['tracking']['opens'] ?? false), (bool) ($data['tracking']['clicks'] ?? false));
            $this->em->persist($job);
            $this->em->flush();

            return [$job, false];
        });
    }

    /**
     * @param array{recipients: list<array<string, string>>} $data contract-valid RecipientBatchRequest
     *
     * @return array{result: array{batch_id: string, accepted_count: int, total_recipients: int}, replayed: bool}
     */
    public function addBatch(SendJob $job, IdempotencyKey $key, string $requestHash, array $data): array
    {
        WorkPermission::assertMayCreateWork($job->getClient());
        return $this->em->wrapInTransaction(function () use ($job, $key, $requestHash, $data): array {
            if (!$this->lock->tryAcquire('send-job-batches|'.$job->getId()->toRfc4122().'|'.$key->value)) {
                throw ApiProblem::idempotencyInProgress();
            }
            $this->em->refresh($job, LockMode::PESSIMISTIC_WRITE);

            $existing = $this->em->getRepository(SendJobRecipientBatch::class)->findOneBy(['sendJob' => $job, 'idempotencyKey' => $key->value]);
            if (null !== $existing) {
                if (!hash_equals($existing->getRequestHash(), $requestHash)) {
                    throw ApiProblem::idempotencyKeyReused();
                }

                return ['result' => $this->batchResult($existing), 'replayed' => true];
            }
            if (!$job->isCollecting()) {
                throw ApiProblem::conflict('job-not-collecting', 'Job is not collecting',
                    \sprintf('Recipients can only be added while the job is collecting (current status: %s).', $job->getStatus()->value));
            }

            $recipients = $data['recipients'];
            $count = \count($recipients);
            if ($count > $this->limits->maxRecipientsPerBatch) {
                throw ApiProblem::unprocessable('batch-too-large', 'Batch too large',
                    \sprintf('At most %d recipients are accepted per request.', $this->limits->maxRecipientsPerBatch),
                    [['pointer' => '/recipients', 'message' => \sprintf('Contains %d recipients.', $count)]]);
            }
            if ($job->getTotalRecipients() + $count > $this->limits->maxRecipientsPerJob) {
                throw ApiProblem::unprocessable('recipient-limit-exceeded', 'Recipient limit exceeded',
                    \sprintf('A send job may hold at most %d recipients; it holds %d and this batch adds %d.',
                        $this->limits->maxRecipientsPerJob, $job->getTotalRecipients(), $count));
            }

            [$rows, $errors] = $this->prepareRecipients($job, $recipients);
            if ([] !== $errors) {
                throw ApiProblem::unprocessable('validation-error', 'Validation failed', 'The batch was rejected as a whole.', $errors);
            }

            $batch = new SendJobRecipientBatch($job, $key->value, $requestHash, $count);
            $this->em->persist($batch);
            $job->addRecipients($count);
            $this->em->flush();
            $this->insertRecipients($job, $batch, $rows);

            return ['result' => ['batch_id' => $batch->getId()->toRfc4122(), 'accepted_count' => $count,
                'total_recipients' => $job->getTotalRecipients()], 'replayed' => false];
        });
    }

    /** Seal the job (POST /v1/send-jobs/{id}/submit); naturally idempotent. */
    public function submit(SendJob $job): SendJob
    {
        WorkPermission::assertMayCreateWork($job->getClient());
        return $this->em->wrapInTransaction(function () use ($job): SendJob {
            $this->em->refresh($job, LockMode::PESSIMISTIC_WRITE);
            if ($job->isSealed()) {
                return $job; // already sealed: 202 with the current state
            }
            if (!$job->isCollecting()) {
                throw ApiProblem::conflict('job-not-submittable', 'Job cannot be submitted',
                    \sprintf('A %s job cannot be submitted.', $job->getStatus()->value));
            }

            $errors = [];
            $total = $job->getTotalRecipients();
            if (0 === $total) {
                throw ApiProblem::unprocessable('empty-job', 'Empty job', 'A send job needs at least one recipient before it can be submitted.');
            }
            if ($total > $this->limits->maxRecipientsPerJob) {
                $errors[] = ['pointer' => '/', 'message' => \sprintf('The job holds %d recipients; the limit is %d.', $total, $this->limits->maxRecipientsPerJob)];
            }
            $stats = $this->connection->fetchAssociative(
                'SELECT count(*) AS n, count(unsubscribe_url) AS with_unsubscribe FROM send_job_recipients WHERE send_job_id = :job',
                ['job' => $job->getId()->toRfc4122()]);
            if ((int) $stats['n'] !== $total) {
                throw new \LogicException('send_jobs.total_recipients disagrees with the staged recipients.');
            }
            $subscription = MessageClass::Subscription === $job->getMessageClass();
            if ($subscription && (null === $job->getListId() || (int) $stats['with_unsubscribe'] !== $total)) {
                $errors[] = ['pointer' => '/', 'message' => 'Subscription jobs need list_id and an unsubscribe_url for every recipient.'];
            }
            if (!$subscription && (null !== $job->getListId() || 0 !== (int) $stats['with_unsubscribe'])) {
                $errors[] = ['pointer' => '/', 'message' => 'Transactional jobs accept neither list_id nor unsubscribe_url.'];
            }
            $errors = array_merge($errors, $this->sendingDomainErrors($job->getSendingDomain()));
            if ([] !== $errors) {
                throw ApiProblem::unprocessable('submit-rejected', 'Job cannot be submitted', 'The job does not satisfy the submit rules.', $errors);
            }

            $job->seal(Clock::now());
            $this->em->flush();

            return $job;
        });
    }

    /**
     * Submit-time sending-domain rule (spec sending_domains.live_sending_rule):
     * verified, and in production also DKIM active - unless the explicitly
     * controlled local/test mode SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS is on
     * (never in production; App\Config\SafetyGuard). A disabled domain is never usable.
     *
     * @return list<array{pointer: string, message: string}>
     */
    private function sendingDomainErrors(SendingDomain $domain): array
    {
        $this->em->refresh($domain);
        if ($domain->isDisabled()) {
            return [['pointer' => '/', 'message' => 'The sending domain is disabled.']];
        }
        if ($this->allowUnverifiedSendingDomains && 'production' !== $this->smarthostEnv) {
            return [];
        }
        $errors = [];
        if (!$domain->isVerified()) {
            $errors[] = ['pointer' => '/', 'message' => 'The sending domain has not passed DNS TXT verification.'];
        }
        if ('production' === $this->smarthostEnv && DkimStatus::Active !== $domain->getDkimStatus()) {
            $errors[] = ['pointer' => '/', 'message' => 'The sending domain has no active DKIM configuration.'];
        }

        return $errors;
    }

    /**
     * Validates every recipient and detects duplicates within the batch and
     * against earlier batches (normalised address, D-18).
     *
     * @param list<array<string, string>> $recipients
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array{pointer: string, message: string}>}
     */
    private function prepareRecipients(SendJob $job, array $recipients): array
    {
        $subscription = MessageClass::Subscription === $job->getMessageClass();
        $rows = [];
        $errors = [];
        $firstIndexByAddress = [];
        foreach ($recipients as $i => $r) {
            $p = "/recipients/$i";
            $normalized = AddressNormalizer::normalize($r['email_address']);
            if (null === $normalized || !AddressNormalizer::isDeliverableSyntax($normalized)) {
                $errors[] = ['pointer' => "$p/email_address", 'message' => 'Not a usable email address.'];
            } elseif (isset($firstIndexByAddress[$normalized])) {
                $errors[] = ['pointer' => "$p/email_address", 'message' => \sprintf(
                    'Duplicate of /recipients/%d/email_address after normalisation.', $firstIndexByAddress[$normalized])];
            } else {
                $firstIndexByAddress[$normalized] = $i;
            }
            if (self::hasControlCharacters($r['subject'])) {
                $errors[] = ['pointer' => "$p/subject", 'message' => 'Control characters (including line breaks) are not allowed in the subject.'];
            }
            if (self::hasControlCharacters($r['external_recipient_reference'])) {
                $errors[] = ['pointer' => "$p/external_recipient_reference", 'message' => 'Control characters are not allowed.'];
            }
            $unsubscribe = $r['unsubscribe_url'] ?? null;
            if ($subscription && null === $unsubscribe) {
                $errors[] = ['pointer' => "$p/unsubscribe_url", 'message' => 'Required for subscription jobs.'];
            } elseif (!$subscription && null !== $unsubscribe) {
                $errors[] = ['pointer' => "$p/unsubscribe_url", 'message' => 'Not accepted for transactional jobs.'];
            } elseif (null !== $unsubscribe && 1 === preg_match('/[\x00-\x20\x7F]/', $unsubscribe)) {
                $errors[] = ['pointer' => "$p/unsubscribe_url", 'message' => 'Whitespace and control characters are not allowed.'];
            }
            if (\count($errors) >= self::MAX_REPORTED_ERRORS) {
                return [[], $errors];
            }
            $html = $r['html_body'] ?? null;
            $text = $r['text_body'] ?? null;
            $rows[] = [
                'external_recipient_reference' => $r['external_recipient_reference'],
                'email_address' => $r['email_address'],
                'normalized_address' => $normalized,
                'subject' => $r['subject'],
                'html_body' => $html,
                'text_body' => $text,
                'unsubscribe_url' => $unsubscribe,
                'content_bytes' => RecipientContent::bytes($r['subject'], $html, $text),
                'content_sha256' => RecipientContent::sha256($r['subject'], $html, $text),
            ];
        }

        if ([] !== $firstIndexByAddress) {
            $existing = $this->connection->fetchFirstColumn(
                'SELECT normalized_address FROM send_job_recipients WHERE send_job_id = ? AND normalized_address = ANY(?::text[])',
                [$job->getId()->toRfc4122(), self::pgTextArray(array_map('strval', array_keys($firstIndexByAddress)))]);
            foreach ($existing as $address) {
                $errors[] = ['pointer' => '/recipients/'.$firstIndexByAddress[$address].'/email_address',
                    'message' => 'Duplicate of a recipient in an earlier batch of this job (after normalisation).'];
            }
        }

        return [$errors === [] ? $rows : [], \array_slice($errors, 0, self::MAX_REPORTED_ERRORS)];
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertRecipients(SendJob $job, SendJobRecipientBatch $batch, array $rows): void
    {
        $now = Clock::now()->format('Y-m-d H:i:s.uP');
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push($params, Uuid::v7()->toRfc4122(), $job->getId()->toRfc4122(), $batch->getId()->toRfc4122(),
                    $row['external_recipient_reference'], $row['email_address'], $row['normalized_address'],
                    $row['subject'], $row['html_body'], $row['text_body'], $row['unsubscribe_url'],
                    $row['content_bytes'], $row['content_sha256'], $now);
            }
            $this->connection->executeStatement(
                'INSERT INTO send_job_recipients (id, send_job_id, batch_id, external_recipient_reference, email_address,
                    normalized_address, subject, html_body, text_body, unsubscribe_url, content_bytes, content_sha256, created_at)
                 VALUES '.implode(', ', $values), $params);
        }
    }

    /**
     * The original response of an accepted batch, reproduced for a replay: the
     * running total is the sum over this batch and every earlier batch of the
     * job (batches are serialised by the job row lock and ordered by creation time).
     *
     * @return array{batch_id: string, accepted_count: int, total_recipients: int}
     */
    private function batchResult(SendJobRecipientBatch $batch): array
    {
        $total = (int) $this->connection->fetchOne(
            'SELECT coalesce(sum(recipient_count), 0) FROM send_job_recipient_batches
              WHERE send_job_id = :job AND (created_at, id) <= (:created, :id)',
            ['job' => $batch->getSendJob()->getId()->toRfc4122(), 'created' => $batch->getCreatedAt()->format('Y-m-d H:i:s.uP'),
             'id' => $batch->getId()->toRfc4122()]);

        return ['batch_id' => $batch->getId()->toRfc4122(), 'accepted_count' => $batch->getRecipientCount(), 'total_recipients' => $total];
    }

    private static function hasControlCharacters(string $value): bool
    {
        return 1 === preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    /** @param list<string> $values */
    private static function pgTextArray(array $values): string
    {
        return '{'.implode(',', array_map(static fn (string $v): string => '"'.addcslashes($v, '"\\').'"', $values)).'}';
    }
}
