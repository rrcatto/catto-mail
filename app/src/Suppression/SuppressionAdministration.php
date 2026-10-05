<?php

declare(strict_types=1);

namespace App\Suppression;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\Suppression;
use App\Entity\User;
use App\Enum\SuppressionReason;
use App\Enum\SuppressionScopeType;
use App\Sending\AddressNormalizer;
use App\Util\Clock;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Operator suppression administration (D-30), used by the smarthost:suppression:*
 * and smarthost:client:global-suppressions console commands. Every change names
 * the operator (actor user) and is audited in the same transaction.
 *
 * Operators create only operator_block / client_abuse_block (address or domain,
 * global or client-scoped); the system reasons belong to the Go delivery daemon
 * and the recipient opt-out to trusted clients. An operator may lift any
 * suppression; lifting sets lifted_at and keeps the row.
 */
final class SuppressionAdministration
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Grants or withdraws the global-opt-out capability; false when nothing changed.
     * $actor is the operator (console command) or, only for the disposable
     * development client, the development bootstrap.
     */
    public function setGlobalSuppressionCapability(Client $client, bool $allowed, AuditActor $actor, string $note): bool
    {
        self::assertNote($note);
        if ($client->canSubmitGlobalSuppressions() === $allowed) {
            return false;
        }

        return $this->em->wrapInTransaction(function () use ($client, $allowed, $actor, $note): bool {
            $client->setCanSubmitGlobalSuppressions($allowed);
            $this->em->flush();
            $this->audit->record($actor, $allowed ? 'client.global_suppressions_enabled' : 'client.global_suppressions_disabled',
                'client', $client->getId()->toRfc4122(), ['can_submit_global_suppressions' => $allowed, 'note' => $note]);

            return true;
        });
    }

    public function create(?Client $scope, string $value, SuppressionScopeType $scopeType, SuppressionReason $reason, ?int $expiresInDays, User $operator, string $note): Suppression
    {
        self::assertNote($note);
        if (!$reason->isOperatorReason()) {
            throw new DomainRuleViolation("Operators create only operator_block or client_abuse_block suppressions ({$reason->value} is created by the delivery daemon or a trusted client).");
        }
        $normalized = SuppressionScopeType::Address === $scopeType
            ? AddressNormalizer::normalize($value)
            : AddressNormalizer::normalizeDomain(rtrim(trim($value), '.'));
        if (null === $normalized || (SuppressionScopeType::Domain === $scopeType && !AddressNormalizer::isHostname($normalized))) {
            throw new DomainRuleViolation("\"$value\" is not a usable {$scopeType->value}.");
        }
        if (null !== $expiresInDays && $expiresInDays < 1) {
            throw new DomainRuleViolation('--expires-in-days must be at least 1.');
        }
        $expires = null === $expiresInDays ? null : Clock::now()->modify("+$expiresInDays days");
        $suppression = Suppression::operatorBlock($scope, $normalized, $scopeType, $reason, $expires);

        return $this->em->wrapInTransaction(function () use ($suppression, $operator, $note): Suppression {
            $this->em->persist($suppression);
            $this->em->flush();
            $this->audit->record(AuditActor::user($operator), 'suppression.operator_created', 'suppression', $suppression->getId()->toRfc4122(), [
                'reason' => $suppression->getReason()->value, 'scope_type' => $suppression->getScopeType()->value,
                'client_id' => $suppression->getClient()?->getId()->toRfc4122(),
                'expires_at' => Clock::rfc3339($suppression->getExpiresAt()), 'note' => $note]);

            return $suppression;
        });
    }

    /** Lifts any suppression (idempotent: false when it was already lifted). Other rows of the address are untouched. */
    public function lift(Suppression $suppression, User $operator, string $note): bool
    {
        self::assertNote($note);

        return $this->em->wrapInTransaction(function () use ($suppression, $operator, $note): bool {
            $this->em->refresh($suppression, LockMode::PESSIMISTIC_WRITE);
            if (!$suppression->lift()) {
                return false;
            }
            $this->em->flush();
            $this->audit->record(AuditActor::user($operator), 'suppression.operator_lifted', 'suppression', $suppression->getId()->toRfc4122(), [
                'reason' => $suppression->getReason()->value, 'client_id' => $suppression->getClient()?->getId()->toRfc4122(),
                'source_client_id' => $suppression->getSourceClient()?->getId()->toRfc4122(),
                'source_message_id' => $suppression->getSourceMessage()?->getId()->toRfc4122(), 'note' => $note]);

            return true;
        });
    }

    public function find(string $id): ?Suppression
    {
        return Uuid::isValid($id) ? $this->em->find(Suppression::class, Uuid::fromString($id)) : null;
    }

    /**
     * Suppressions matching an address (its address rows and its domain's rows) or
     * all, newest first.
     *
     * @return list<Suppression>
     */
    public function search(?string $address, ?Client $client, bool $activeOnly, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()->select('s')->from(Suppression::class, 's')
            ->orderBy('s.createdAt', 'DESC')->setMaxResults(max(1, min($limit, 1000)));
        if (null !== $address) {
            $normalized = AddressNormalizer::normalize($address) ?? throw new DomainRuleViolation("\"$address\" has no normalised form.");
            $qb->andWhere("(s.scopeType = 'address' AND s.addressOrDomain = :a) OR (s.scopeType = 'domain' AND s.addressOrDomain = :d)")
                ->setParameter('a', $normalized)->setParameter('d', AddressNormalizer::domainOf($normalized));
        }
        if (null !== $client) {
            $qb->andWhere('s.client = :c OR s.sourceClient = :c')->setParameter('c', $client->getId(), 'uuid');
        }
        if ($activeOnly) {
            $qb->andWhere('s.liftedAt IS NULL AND (s.expiresAt IS NULL OR s.expiresAt > :now)')->setParameter('now', Clock::now(), 'timestamptz');
        }

        return $qb->getQuery()->getResult();
    }

    private static function assertNote(string $note): void
    {
        if ('' === trim($note)) {
            throw new DomainRuleViolation('A written --note is required (it is kept in the audit log).');
        }
    }
}
