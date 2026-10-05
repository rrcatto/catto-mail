<?php

declare(strict_types=1);

namespace App\Dsn;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\Message;
use App\Entity\UnmatchedDsn;
use App\Entity\User;
use App\Enum\UnmatchedDsnStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Operator workflow for DSNs that matched no message (D-05; Phase 5 console
 * commands until the Phase 6 operator dashboard).
 *
 * Symfony never interprets a DSN or writes a message event: a match request only
 * records the operator's candidate message (status match_requested) and wakes the
 * Go delivery daemon (NOTIFY smarthost_unmatched_dsn_work), which re-reads the
 * retained DSN, appends the transport event (source unmatched_dsn_resolution)
 * with projection and suppression policy, and marks the row matched - or returns
 * it to open with the reason in detail_json. Dismissal needs a written reason.
 * Rows are never deleted here.
 */
final class UnmatchedDsnAdministration
{
    /** LISTEN channel of the Go delivery daemon (postfix-integration §5). */
    public const NOTIFY_CHANNEL = 'smarthost_unmatched_dsn_work';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly AuditLogger $audit,
    ) {
    }

    public function find(string $id): ?UnmatchedDsn
    {
        return Uuid::isValid($id) ? $this->em->find(UnmatchedDsn::class, Uuid::fromString($id)) : null;
    }

    /** @return list<UnmatchedDsn> oldest first (the operator queue) */
    public function list(?UnmatchedDsnStatus $status, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()->select('d')->from(UnmatchedDsn::class, 'd')
            ->orderBy('d.receivedAt', 'ASC')->addOrderBy('d.id', 'ASC')->setMaxResults(max(1, min($limit, 1000)));
        if (null !== $status) {
            $qb->where('d.status = :s')->setParameter('s', $status->value);
        }

        return $qb->getQuery()->getResult();
    }

    public function requestMatch(UnmatchedDsn $dsn, string $messageId, User $operator, ?string $note): void
    {
        $message = Uuid::isValid($messageId) ? $this->em->find(Message::class, Uuid::fromString($messageId)) : null;
        if (null === $message) {
            throw new DomainRuleViolation("No message $messageId.");
        }
        $this->em->wrapInTransaction(function () use ($dsn, $message, $operator, $note): void {
            $this->em->refresh($dsn, LockMode::PESSIMISTIC_WRITE);
            try {
                $dsn->requestMatch($message, $operator, null === $note || '' === trim($note) ? null : trim($note));
            } catch (\DomainException $e) {
                throw new DomainRuleViolation($e->getMessage());
            }
            $this->em->flush();
            $this->audit->record(AuditActor::user($operator), 'unmatched_dsn.match_requested', 'unmatched_dsn', $dsn->getId()->toRfc4122(), [
                'message_id' => $message->getId()->toRfc4122(), 'note' => $dsn->getResolutionNote()]);
            $this->connection->executeStatement('SELECT pg_notify(:channel, :id)', ['channel' => self::NOTIFY_CHANNEL, 'id' => $dsn->getId()->toRfc4122()]);
        });
    }

    public function dismiss(UnmatchedDsn $dsn, string $reason, User $operator): void
    {
        $this->em->wrapInTransaction(function () use ($dsn, $reason, $operator): void {
            $this->em->refresh($dsn, LockMode::PESSIMISTIC_WRITE);
            try {
                $dsn->dismiss($reason);
            } catch (\DomainException $e) {
                throw new DomainRuleViolation($e->getMessage());
            }
            $this->em->flush();
            $this->audit->record(AuditActor::user($operator), 'unmatched_dsn.dismissed', 'unmatched_dsn', $dsn->getId()->toRfc4122(),
                ['reason' => $dsn->getResolutionNote()]);
        });
    }
}
