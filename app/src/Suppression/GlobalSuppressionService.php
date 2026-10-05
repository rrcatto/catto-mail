<?php

declare(strict_types=1);

namespace App\Suppression;

use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Entity\Client;
use App\Entity\GlobalSuppressionRequest;
use App\Entity\Suppression;
use App\Enum\GlobalSuppressionRequestOperation;
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
 * (403 otherwise). Creating an opt-out is a recipient-safety (do-not-contact)
 * operation, not work creation, so a trusted client may create one even while
 * `pending_approval` or `suspended` (D-37); lifting one makes an address sendable
 * again and requires an `active` or `throttled` client. Closed clients cannot
 * authenticate. The client can create nothing but `recipient_global_opt_out`,
 * address-scoped and global (client_id NULL); the reporter is
 * `source_client_id`. An ordinary list unsubscribe is never Smarthost state.
 *
 * Idempotency (D-38) is durable and independent of the suppression row: every
 * accepted request is recorded in global_suppression_requests as
 * (client, operation, Idempotency-Key) -> canonical request hash, resulting
 * suppression, original status (201 created / 200 already active). The same key
 * with the same body replays that result (same resource, same status,
 * Idempotent-Replayed) even after the suppression was lifted; with a different
 * body it is 422; while the first request is in flight it is 409 (advisory lock),
 * and the unique index is the final guarantee. Concurrent reports of one address
 * with different keys are serialised by a lock on (client, address), so at most
 * one active opt-out exists (suppressions_source_client_address_active_uq).
 * Lifting sets lifted_at on this client's own opt-out only; other suppressions of
 * the address (other reasons, other reporters) are untouched.
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
        self::assertMayCreate($client);
        $normalized = AddressNormalizer::normalize($data['email_address']);
        if (null === $normalized || !AddressNormalizer::isDeliverableSyntax($normalized)) {
            throw ApiProblem::unprocessable('validation-error', 'Validation failed', 'The email address is not usable.',
                [['pointer' => '/email_address', 'message' => 'Not a usable email address.']]);
        }

        return $this->em->wrapInTransaction(function () use ($client, $actor, $key, $requestHash, $data, $normalized): array {
            if (!$this->lock->tryAcquire('global-suppressions|'.$client->getId()->toRfc4122().'|'.$key->value)) {
                throw ApiProblem::idempotencyInProgress();
            }
            $operation = GlobalSuppressionRequestOperation::CreateGlobalOptOut;
            $previous = $this->em->getRepository(GlobalSuppressionRequest::class)
                ->findOneBy(['client' => $client, 'operation' => $operation, 'idempotencyKey' => $key->value]);
            if (null !== $previous) { // the key's historical result, whatever the suppression's state now
                return hash_equals($previous->getRequestHash(), $requestHash)
                    ? [$previous->getSuppression(), $previous->getResponseStatus(), true]
                    : throw ApiProblem::idempotencyKeyReused();
            }
            // Serialise concurrent reports of the same address by this client (different keys).
            $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:scope, 0))',
                ['scope' => 'global-opt-out|'.$client->getId()->toRfc4122().'|'.$normalized]);
            $externalReference = $data['external_reference'] ?? null;
            $optOut = $this->activeOptOut($client, $normalized);
            $status = 200;
            if (null === $optOut) {
                $optOut = Suppression::recipientGlobalOptOut($client, $normalized, $externalReference);
                $this->em->persist($optOut);
                $status = 201;
            }
            $this->em->persist(new GlobalSuppressionRequest($client, $operation, $key->value, $requestHash, $optOut, $status, $externalReference));
            $this->em->flush();
            $this->audit->record($actor, 201 === $status ? 'suppression.global_opt_out_created' : 'suppression.global_opt_out_reaffirmed',
                'suppression', $optOut->getId()->toRfc4122(), [
                    'reason' => SuppressionReason::RecipientGlobalOptOut->value,
                    'source_client_id' => $client->getId()->toRfc4122(),
                    'external_reference' => $externalReference,
                ]);

            return [$optOut, $status, false];
        });
    }

    /**
     * Lifts this client's own active opt-out. Idempotent: an already lifted
     * opt-out is returned unchanged (and not audited again).
     */
    public function lift(Client $client, AuditActor $actor, Suppression $optOut): Suppression
    {
        self::assertMayLift($client);

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

    /**
     * Creating an opt-out needs only the operator-granted capability (D-37): it is a
     * recipient-safety operation, allowed for pending_approval and suspended clients
     * too. Checked before the body is read.
     */
    public static function assertMayCreate(Client $client): void
    {
        if (!$client->canSubmitGlobalSuppressions()) {
            throw ApiProblem::forbidden('This client is not permitted to report recipient global opt-outs (an operator grants this capability).');
        }
    }

    /** Lifting makes an address sendable again: the capability and an active or throttled client (D-31, D-37). */
    public static function assertMayLift(Client $client): void
    {
        self::assertMayCreate($client);
        if (!$client->mayCreateWork()) {
            throw ApiProblem::forbidden(\sprintf('A client in status "%s" may not lift a global opt-out.', $client->getStatus()->value));
        }
    }
}
