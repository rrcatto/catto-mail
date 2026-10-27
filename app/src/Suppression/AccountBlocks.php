<?php

declare(strict_types=1);

namespace App\Suppression;

use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\GlobalSuppressionRequest;
use App\Entity\Suppression;
use App\Enum\GlobalSuppressionRequestOperation;
use App\Enum\SuppressionReason;
use App\Enum\SuppressionScopeType;
use App\Idempotency\IdempotencyLock;
use App\Sending\AddressNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * An account's own blocks (specification 2.13, owner decision 2026-10-27): an address or a
 * whole domain the account never sends to. Each is a suppressions row with reason
 * account_block and client_id the account, so the Go delivery daemon stops the account's
 * messages to it when it creates them and again before submission; other accounts are never
 * affected (an installation-wide block is an operator's, SuppressionAdministration).
 *
 * Created by the account's admins in the dashboard or by its application (POST /v1/blocks).
 * Like an opt-out it is a do-not-contact operation, allowed in every account status that
 * authenticates; lifting makes mail sendable again and needs an active or throttled account.
 * API requests are idempotent like the opt-outs (D-38): the accepted request is recorded in
 * global_suppression_requests (operation create_account_block) and the same key and body
 * replay its result. One active block per account, scope and value
 * (suppressions_account_block_active_uq): blocking a blocked value returns the block (200).
 */
final class AccountBlocks
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly IdempotencyLock $lock,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Blocks a normalised value for the account; with $key (the API), the request is recorded
     * for idempotency.
     *
     * @return array{0: Suppression, 1: int, 2: bool} the block, 201 created or 200 already active, and whether this is a replay
     */
    public function block(Client $account, AuditActor $actor, string $value, SuppressionScopeType $scope, ?string $externalReference = null,
        ?IdempotencyKey $key = null, ?string $requestHash = null): array
    {
        return $this->em->wrapInTransaction(function () use ($account, $actor, $value, $scope, $externalReference, $key, $requestHash): array {
            $operation = GlobalSuppressionRequestOperation::CreateAccountBlock;
            if (null !== $key) {
                if (!$this->lock->tryAcquire('account-blocks|'.$account->getId()->toRfc4122().'|'.$key->value)) {
                    throw ApiProblem::idempotencyInProgress();
                }
                $previous = $this->em->getRepository(GlobalSuppressionRequest::class)
                    ->findOneBy(['client' => $account, 'operation' => $operation, 'idempotencyKey' => $key->value]);
                if (null !== $previous) { // the key's historical result, whatever the block's state now
                    return hash_equals($previous->getRequestHash(), (string) $requestHash)
                        ? [$previous->getSuppression(), $previous->getResponseStatus(), true]
                        : throw ApiProblem::idempotencyKeyReused();
                }
            }
            // Serialise concurrent blocks of the same value by this account.
            $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:scope, 0))',
                ['scope' => 'account-block|'.$account->getId()->toRfc4122().'|'.$scope->value.'|'.$value]);
            $block = $this->active($account, $scope, $value);
            $status = 200;
            if (null === $block) {
                $block = Suppression::accountBlock($account, $value, $scope, $externalReference);
                $this->em->persist($block);
                $status = 201;
            }
            if (null !== $key) {
                $this->em->persist(new GlobalSuppressionRequest($account, $operation, $key->value, (string) $requestHash, $block, $status, $externalReference));
            }
            $this->em->flush();
            $this->audit->record($actor, 201 === $status ? 'suppression.account_block_created' : 'suppression.account_block_reaffirmed',
                'suppression', $block->getId()->toRfc4122(), [
                    'client_id' => $account->getId()->toRfc4122(), 'scope_type' => $scope->value, 'external_reference' => $externalReference,
                ]);

            return [$block, $status, false];
        });
    }

    /** Lifts one of the account's blocks (idempotent: a lifted block is returned unchanged and not audited again). */
    public function lift(Client $account, AuditActor $actor, Suppression $block): Suppression
    {
        if (!$account->mayCreateWork()) {
            throw new DomainRuleViolation(\sprintf('An account that is "%s" may not lift a block: lifting makes mail sendable again.', $account->getStatus()->value));
        }

        return $this->em->wrapInTransaction(function () use ($account, $actor, $block): Suppression {
            $this->em->refresh($block, LockMode::PESSIMISTIC_WRITE);
            if ($block->lift()) {
                $this->em->flush();
                $this->audit->record($actor, 'suppression.account_block_lifted', 'suppression', $block->getId()->toRfc4122(), [
                    'client_id' => $account->getId()->toRfc4122(), 'scope_type' => $block->getScopeType()->value,
                ]);
            }

            return $block;
        });
    }

    /** One of the account's own blocks by id; anything else (another account's, another reason) is "not found". */
    public function find(Client $account, string $id): ?Suppression
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->em->getRepository(Suppression::class)->findOneBy([
            'id' => Uuid::fromString($id), 'client' => $account, 'reason' => SuppressionReason::AccountBlock]);
    }

    /**
     * The account's blocks, newest first (id descending: ids are UUIDv7), after the cursor id.
     *
     * @param 'active'|'lifted'|'all' $status
     *
     * @return list<Suppression>
     */
    public function list(Client $account, string $status, ?string $value, ?string $afterId, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()->select('s')->from(Suppression::class, 's')
            ->where('s.client = :c AND s.reason = :r')->setParameter('c', $account->getId(), 'uuid')
            ->setParameter('r', SuppressionReason::AccountBlock->value)
            ->orderBy('s.id', 'DESC')->setMaxResults($limit);
        if ('active' === $status) {
            $qb->andWhere('s.liftedAt IS NULL');
        } elseif ('lifted' === $status) {
            $qb->andWhere('s.liftedAt IS NOT NULL');
        }
        if (null !== $value) {
            $qb->andWhere('s.addressOrDomain = :v')->setParameter('v', $value);
        }
        if (null !== $afterId) {
            $qb->andWhere('s.id < :after')->setParameter('after', Uuid::fromString($afterId), 'uuid');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * What a person typed (the dashboard's one field): an address, a domain, or "@domain".
     *
     * @return array{0: string, 1: SuppressionScopeType} the normalised value and its scope
     */
    public static function parse(string $input): array
    {
        $input = trim($input);
        if (str_starts_with($input, '@')) {
            return [self::domain(substr($input, 1)), SuppressionScopeType::Domain];
        }

        return str_contains($input, '@') ? [self::address($input), SuppressionScopeType::Address] : [self::domain($input), SuppressionScopeType::Domain];
    }

    public static function address(string $input): string
    {
        $normalized = AddressNormalizer::normalize($input);
        if (null === $normalized || !AddressNormalizer::isDeliverableSyntax($normalized)) {
            throw new DomainRuleViolation(\sprintf('"%s" is not a usable email address.', trim($input)));
        }

        return $normalized;
    }

    public static function domain(string $input): string
    {
        $input = trim($input);
        $normalized = str_contains($input, '@') ? null : AddressNormalizer::normalizeDomain(rtrim($input, '.'));
        if (null === $normalized || !AddressNormalizer::isHostname($normalized)) {
            throw new DomainRuleViolation(\sprintf('"%s" is not a domain name such as example.org.', $input));
        }

        return $normalized;
    }

    private function active(Client $account, SuppressionScopeType $scope, string $value): ?Suppression
    {
        return $this->em->createQuery('SELECT s FROM App\Entity\Suppression s
            WHERE s.client = :c AND s.scopeType = :t AND s.addressOrDomain = :v AND s.reason = :r AND s.liftedAt IS NULL')
            ->setParameter('c', $account->getId(), 'uuid')->setParameter('t', $scope->value)->setParameter('v', $value)
            ->setParameter('r', SuppressionReason::AccountBlock->value)
            ->getOneOrNullResult();
    }

    /** Whether the account may lift a block now (an active or throttled account). */
    public static function mayLift(Client $account): bool
    {
        return $account->mayCreateWork();
    }
}
