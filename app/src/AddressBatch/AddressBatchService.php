<?php

declare(strict_types=1);

namespace App\AddressBatch;

use App\Api\IdempotencyKey;
use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\User;
use App\Enum\AddressBatchPurpose;
use App\Enum\BatchReviewDecision;
use App\Sending\AddressNormalizer;
use App\Util\Clock;
use App\Validation\ValidationJobService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Administrator address batches (specification 2.11): import an uploaded list, validate
 * it through the Email Validator on behalf of the batch's client, and record the
 * administrator's review and typo decisions and the batch's compliance approval. Every
 * change is audited. Sending is in BatchSender; recipients' answers in RepermissionService.
 *
 * The validator does the validation: this service only creates ordinary validation jobs
 * (the client's quotas apply) whose addresses carry the entry id as their external
 * reference, and links each entry to its result.
 */
final class AddressBatchService
{
    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditLogger $audit,
        private readonly ValidationJobService $validationJobs,
    ) {
    }

    /**
     * @param array{rows: list<array<string, mixed>>, counts: array<string, int>, format: string, column: ?string, sha256: string} $parsed
     */
    public function import(Client $client, array $parsed, string $filename, string $name, ?string $description, ?string $source,
        AddressBatchPurpose $purpose, ?string $listId, User $user): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 200) {
            throw new DomainRuleViolation('Give the batch a name (at most 200 characters).');
        }
        $listId = null === $listId || '' === trim($listId) ? null : trim($listId);
        if (null !== $listId && 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $listId)) {
            throw new DomainRuleViolation('The list identifier may contain letters, digits, dots, hyphens and underscores (e.g. news.example.org).');
        }
        if (AddressBatchPurpose::Repermission === $purpose && null === $listId) {
            throw new DomainRuleViolation('A re-permission batch needs the list identifier (List-Id) of the list it asks about.');
        }
        $id = Uuid::v7()->toRfc4122();
        $this->connection->transactional(function () use ($id, $client, $parsed, $filename, $name, $description, $source, $purpose, $listId, $user): void {
            $c = $parsed['counts'];
            $this->connection->insert('address_batches', [
                'id' => $id, 'client_id' => $client->getId()->toRfc4122(), 'name' => $name,
                'description' => self::optional($description, 2000), 'source' => self::optional($source, 500),
                'purpose' => $purpose->value, 'original_filename' => mb_substr(basename($filename), 0, 255) ?: 'upload',
                'file_format' => $parsed['format'], 'email_column' => 'csv' === $parsed['format'] ? $parsed['column'] : null,
                'file_sha256' => $parsed['sha256'], 'uploaded_by_user_id' => $user->getId()->toRfc4122(),
                'created_at' => Clock::now()->format('Y-m-d H:i:s.uP'), 'data_rows' => $c['data_rows'],
                'imported_count' => $c['imported'], 'duplicate_count' => $c['duplicate'], 'malformed_count' => $c['malformed'],
                'blank_count' => $c['blank'], 'list_id' => $listId,
            ]);
            $idsByRow = [];
            foreach ($parsed['rows'] as $r) {
                $idsByRow[$r['row']] = Uuid::v7()->toRfc4122();
            }
            foreach (array_chunk($parsed['rows'], self::INSERT_CHUNK) as $chunk) {
                $values = $params = [];
                foreach ($chunk as $r) {
                    $values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                    array_push($params, $idsByRow[$r['row']], $id, $r['row'], $r['original'], $r['normalized'], $r['outcome'],
                        $r['detail'], null === $r['duplicate_of_row'] ? null : $idsByRow[$r['duplicate_of_row']]);
                }
                $this->connection->executeStatement('INSERT INTO address_batch_entries (id, batch_id, row_number, original_value,
                    normalized_address, outcome, outcome_detail, duplicate_of_entry_id) VALUES '.implode(', ', $values), $params);
            }
            $this->audit->record(AuditActor::user($user), 'address_batch.imported', 'address_batch', $id, [
                'client_id' => $client->getId()->toRfc4122(), 'filename' => basename($filename), 'purpose' => $purpose->value,
                'counts' => $c, 'sha256' => $parsed['sha256']]);
        });

        return $id;
    }

    /**
     * Starts validation of every imported entry that has no result yet (new batches, and
     * accepted typo corrections), in jobs of at most 10,000 addresses.
     *
     * @return int the number of addresses submitted to the validator
     */
    public function validate(string $batchId, Client $client, User $user): int
    {
        $entries = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id::text AS id, normalized_address FROM address_batch_entries
             WHERE batch_id = ? AND outcome = 'imported' AND validation_address_id IS NULL AND typo_decision IS DISTINCT FROM 'accepted'
             ORDER BY row_number, id
            SQL, [$batchId]);
        if ([] === $entries) {
            throw new DomainRuleViolation('Every address of this batch already has a validation result or is being validated.');
        }
        foreach (array_chunk($entries, BatchFileParser::MAX_ROWS) as $chunk) {
            $key = IdempotencyKey::internal('address-batch-'.$batchId.'-'.Uuid::v7()->toRfc4122());
            [$job] = $this->validationJobs->create($client, $key, hash('sha256', $key->value), [
                'external_reference' => 'address-batch:'.$batchId,
                'addresses' => array_map(static fn (array $e): array => ['address' => (string) $e['normalized_address'],
                    'external_address_reference' => (string) $e['id']], $chunk),
            ]);
            $this->connection->executeStatement(<<<'SQL'
                UPDATE address_batch_entries e SET validation_address_id = va.id
                  FROM validation_addresses va
                 WHERE va.job_id = ? AND va.external_address_reference = e.id::text AND e.batch_id = ?
                SQL, [$job->getId()->toRfc4122(), $batchId]);
        }
        $this->audit->record(AuditActor::user($user), 'address_batch.validation_started', 'address_batch', $batchId, ['addresses' => \count($entries)]);

        return \count($entries);
    }

    /** @param list<string> $entryIds */
    public function review(string $batchId, array $entryIds, ?BatchReviewDecision $decision, User $user): int
    {
        if ([] === $entryIds) {
            return 0;
        }
        $n = (int) $this->connection->executeStatement(
            "UPDATE address_batch_entries SET review_decision = ? WHERE batch_id = ? AND outcome = 'imported' AND id IN (?)",
            [$decision?->value, $batchId, array_values(array_filter($entryIds, static fn ($i) => Uuid::isValid((string) $i)))],
            [2 => ArrayParameterType::STRING]);
        $this->audit->record(AuditActor::user($user), 'address_batch.review_decided', 'address_batch', $batchId,
            ['decision' => $decision?->value ?? 'cleared', 'entries' => $n]);

        return $n;
    }

    /**
     * The administrator's decision on a typo suggestion. Accepting never edits the original:
     * the suggested address is added as a correction entry (validated like any other) and the
     * original is no longer sent to.
     */
    public function decideTypo(string $batchId, string $entryId, bool $accept, User $user): void
    {
        $this->connection->transactional(function () use ($batchId, $entryId, $accept, $user): void {
            $e = $this->connection->fetchAssociative(<<<'SQL'
                SELECT e.id::text AS id, e.row_number, e.typo_decision, va.suggested_address
                  FROM address_batch_entries e JOIN validation_addresses va ON va.id = e.validation_address_id
                 WHERE e.id = ? AND e.batch_id = ? AND e.outcome = 'imported' FOR UPDATE OF e
                SQL, [$entryId, $batchId]);
            if (false === $e || null === $e['suggested_address']) {
                throw new DomainRuleViolation('This address has no typo suggestion.');
            }
            if (null !== $e['typo_decision']) {
                throw new DomainRuleViolation('This suggestion was already decided.');
            }
            $this->connection->update('address_batch_entries', ['typo_decision' => $accept ? 'accepted' : 'rejected'], ['id' => $entryId]);
            $detail = ['entry_id' => $entryId, 'decision' => $accept ? 'accepted' : 'rejected'];
            if ($accept) {
                $normalized = AddressNormalizer::normalize((string) $e['suggested_address']);
                if (null === $normalized) {
                    throw new DomainRuleViolation('The suggestion is not a usable address.');
                }
                $existing = $this->connection->fetchOne(
                    "SELECT id::text FROM address_batch_entries WHERE batch_id = ? AND outcome = 'imported' AND normalized_address = ?", [$batchId, $normalized]);
                $this->connection->insert('address_batch_entries', [
                    'id' => $newId = Uuid::v7()->toRfc4122(), 'batch_id' => $batchId, 'row_number' => $e['row_number'],
                    'original_value' => (string) $e['suggested_address'], 'normalized_address' => $normalized,
                    'outcome' => false === $existing ? 'imported' : 'duplicate',
                    'outcome_detail' => false === $existing ? 'Accepted typo correction of row '.$e['row_number'].'.'
                        : 'Accepted typo correction of row '.$e['row_number'].'; the address is already in the batch.',
                    'duplicate_of_entry_id' => false === $existing ? null : $existing,
                    'corrects_entry_id' => false === $existing ? $entryId : null,
                    'created_at' => Clock::now()->format('Y-m-d H:i:s.uP'),
                ]);
                $detail['correction_entry_id'] = $newId;
            }
            $this->audit->record(AuditActor::user($user), 'address_batch.typo_decided', 'address_batch', $batchId, $detail);
        });
    }

    /**
     * The live-sending compliance gate for this batch: before anything but a seed test is
     * sent, an administrator records who approved sending to these people and on what
     * basis (e.g. the compliance decision's reference). Technical validation is never consent.
     */
    public function approveCompliance(string $batchId, string $reference, User $user): void
    {
        $reference = trim($reference);
        if (mb_strlen($reference) < 3 || mb_strlen($reference) > 1000) {
            throw new DomainRuleViolation('Record the approval: who approved sending to this list, when, and on what basis (3 to 1000 characters).');
        }
        $n = $this->connection->executeStatement(<<<'SQL'
            UPDATE address_batches SET compliance_approved_at = now(), compliance_approved_by_user_id = ?, compliance_reference = ?
             WHERE id = ? AND compliance_approved_at IS NULL
            SQL, [$user->getId()->toRfc4122(), $reference, $batchId]);
        if (1 !== $n) {
            throw new DomainRuleViolation('This batch is already approved.');
        }
        $this->audit->record(AuditActor::user($user), 'address_batch.compliance_approved', 'address_batch', $batchId, ['reference' => $reference]);
    }

    public function setListId(string $batchId, string $listId, User $user): void
    {
        $listId = trim($listId);
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $listId)) {
            throw new DomainRuleViolation('The list identifier may contain letters, digits, dots, hyphens and underscores (e.g. news.example.org).');
        }
        $this->connection->update('address_batches', ['list_id' => $listId], ['id' => $batchId]);
        $this->audit->record(AuditActor::user($user), 'address_batch.list_id_set', 'address_batch', $batchId, ['list_id' => $listId]);
    }

    private static function optional(?string $v, int $max): ?string
    {
        $v = null === $v ? null : trim($v);

        return null === $v || '' === $v ? null : mb_substr($v, 0, $max);
    }
}
