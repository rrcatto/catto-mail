<?php

declare(strict_types=1);

namespace App\Suppression;

use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\WorkPermission;
use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Entity\Client;
use App\Entity\Suppression;
use App\Enum\SuppressionReason;
use App\Idempotency\IdempotencyLock;
use App\Sending\AddressNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Recipient global opt-outs reported by trusted clients (D-30,
 * POST /v1/global-suppressions and .../{id}/lift).
 *
 * Only clients an operator granted `can_submit_global_suppressions` may use it
 * (403 otherwise), and only active/throttled ones (D-31). The client can create
 * nothing but `recipient_global_opt_out`, address-scoped and global
 * (client_id NULL); the reporter is `source_client_id`. An ordinary list
 * unsubscribe is never Smarthost state and has no endpoint.
 *
 * Creation is idempotent per (client, Idempotency-Key) and concurrency safe: an
 * in-flight duplicate gets 409; a request for an address this client already has
 * an active opt-out for returns that row (200), serialised by a transaction-scoped
 * advisory lock on (client, address) and backed by the partial unique index
 * suppressions_source_client_address_active_uq. Lifting sets lifted_at on this
 * client's own opt-out only; other suppressions of the address (other reasons,
 * other reporters) are untouched, so the address may stay suppressed.
 */
final class GlobalSuppressionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly IdempotencyLock $lock,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array{email_address: string, external_reference?: string} $data contract-valid body
     *
     * @return array{0: Suppression, 1: int, 2: bool} the opt-out, the HTTP status (201 created / 200 existing) and whether this is an idempotent replay
     */
    public function create(Client $client, AuditActor $actor, IdempotencyKey $key, string $requestHash, array $data): array
    {
        self::assertMayReport($client);
        $normalized = AddressNormalizer::normalize($data['email_address']);
        if (null === $normalized || !AddressNormalizer::isDeliverableSyntax($normalized)) {
            throw ApiProblem::unprocessable('validation-error', 'Validation failed', 'The email address is not usable.',
                [['pointer' => '/email_address', 'message' => 'Not a usable email address.']]);
        }

        return $this->em->wrapInTransaction(function () use ($client, $actor, $key, $requestHash, $data, $normalized): array {
            if (!$this->lock->tryAcquire('global-suppressions|'.$client->getId()->toRfc4122().'|'.$key->value)) {
                throw ApiProblem::idempotencyInProgress();
            }
            $replay = $this->em->getRepository(Suppression::class)->findOneBy(['sourceClient' => $client, 'idempotencyKey' => $key->value]);
            if (null !== $replay) {
                return hash_equals((string) $replay->getRequestHash(), $requestHash) ? [$replay, 201, true] : throw ApiProblem::idempotencyKeyReused();
            }
            // Serialise concurrent reports of the same address by this client (different keys).
            $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:scope, 0))',
                ['scope' => 'global-opt-out|'.$client->getId()->toRfc4122().'|'.$normalized]);
            $active = $this->activeOptOut($client, $normalized);
            if (null !== $active) {
                return [$active, 200, false];
            }

            $optOut = Suppression::recipientGlobalOptOut($client, $normalized, $key->value, $requestHash, $data['external_reference'] ?? null);
            $this->em->persist($optOut);
            $this->em->flush();
            $this->audit->record($actor, 'suppression.global_opt_out_created', 'suppression', $optOut->getId()->toRfc4122(), [
                'reason' => SuppressionReason::RecipientGlobalOptOut->value,
                'source_client_id' => $client->getId()->toRfc4122(),
                'external_reference' => $optOut->getExternalReference(),
            ]);

            return [$optOut, 201, false];
        });
    }

    /**
     * Lifts this client's own active opt-out. Idempotent: an already lifted
     * opt-out is returned unchanged (and not audited again).
     */
    public function lift(Client $client, AuditActor $actor, Suppression $optOut): Suppression
    {
        self::assertMayReport($client);

        return $this->em->wrapInTransaction(function () use ($client, $actor, $optOut): Suppression {
            $this->em->refresh($optOut, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if ($optOut->lift()) {
                $this->em->flush();
                $this->audit->record($actor, 'suppression.global_opt_out_lifted', 'suppression', $optOut->getId()->toRfc4122(), [
                    'reason' => $optOut->getReason()->value,
                    'source_client_id' => $client->getId()->toRfc4122(),
                ]);
            }

            return $optOut;
        });
    }

    /** The client's own opt-out by id (any other id, foreign or not an opt-out, is "not found"). */
    public function ownOptOut(Client $client, string $id): ?Suppression
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->em->getRepository(Suppression::class)->findOneBy([
            'id' => Uuid::fromString($id), 'sourceClient' => $client, 'reason' => SuppressionReason::RecipientGlobalOptOut]);
    }

    private function activeOptOut(Client $client, string $normalized): ?Suppression
    {
        return $this->em->createQuery('SELECT s FROM App\Entity\Suppression s
            WHERE s.sourceClient = :c AND s.addressOrDomain = :a AND s.reason = :r AND s.liftedAt IS NULL')
            ->setParameter('c', $client->getId(), 'uuid')->setParameter('a', $normalized)
            ->setParameter('r', SuppressionReason::RecipientGlobalOptOut->value)
            ->getOneOrNullResult();
    }

    /** 403 unless the client is active/throttled (D-31) and holds the operator-granted capability. Checked before the body is read. */
    public static function assertMayReport(Client $client): void
    {
        WorkPermission::assertMayCreateWork($client);
        if (!$client->canSubmitGlobalSuppressions()) {
            throw ApiProblem::forbidden('This client is not permitted to report recipient global opt-outs (an operator grants this capability).');
        }
    }
}
