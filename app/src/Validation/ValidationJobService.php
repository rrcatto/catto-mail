<?php

declare(strict_types=1);

namespace App\Validation;

use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Entity\Client;
use App\Entity\ValidationJob;
use App\Idempotency\IdempotencyLock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates validation jobs (POST /v1/validation-jobs). Phase 2 only creates the
 * work: one `queued` job and one `pending` validation_addresses row per submitted
 * address, in submission order (ids are monotonic UUIDv7). Nothing here validates
 * an address; the Python validator (Phase 3) claims the rows.
 */
final class ValidationJobService
{
    private const INSERT_CHUNK = 1000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly IdempotencyLock $lock,
    ) {
    }

    /**
     * @param array{external_reference?: string, addresses: list<array{address: string, external_address_reference?: string}>} $data
     *                                                                                                                                 contract-valid body
     *
     * @return array{0: ValidationJob, 1: bool} the job and whether this is an idempotent replay
     */
    public function create(Client $client, IdempotencyKey $key, string $requestHash, array $data): array
    {
        return $this->em->wrapInTransaction(function () use ($client, $key, $requestHash, $data): array {
            if (!$this->lock->tryAcquire('validation-jobs|'.$client->getId()->toRfc4122().'|'.$key->value)) {
                throw ApiProblem::idempotencyInProgress();
            }
            $existing = $this->em->getRepository(ValidationJob::class)->findOneBy(['client' => $client, 'idempotencyKey' => $key->value]);
            if (null !== $existing) {
                return hash_equals($existing->getRequestHash(), $requestHash) ? [$existing, true] : throw ApiProblem::idempotencyKeyReused();
            }

            $job = new ValidationJob($client, $key->value, $requestHash, \count($data['addresses']), $data['external_reference'] ?? null);
            $this->em->persist($job);
            $this->em->flush();
            $this->insertAddresses($job->getId(), $data['addresses']);

            return [$job, false];
        });
    }

    /** @param list<array{address: string, external_address_reference?: string}> $addresses */
    private function insertAddresses(Uuid $jobId, array $addresses): void
    {
        foreach (array_chunk($addresses, self::INSERT_CHUNK) as $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $address) {
                $rows[] = '(?, ?, ?, ?)';
                array_push($params, Uuid::v7()->toRfc4122(), $jobId->toRfc4122(),
                    $address['external_address_reference'] ?? null, $address['address']);
            }
            $this->connection->executeStatement(
                'INSERT INTO validation_addresses (id, job_id, external_address_reference, original_address) VALUES '.implode(', ', $rows),
                $params);
        }
    }
}
