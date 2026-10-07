<?php

declare(strict_types=1);

namespace App\Reputation;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Operator acknowledgement of a reputation alert (Phase 9, PLATFORM.ABUSE.MANAGE): the
 * operator records what they found or did (for example "throttled the client, asked them
 * to clean the list"). Acknowledging never changes the client; restrictions are separate,
 * audited lifecycle actions. An escalation to critical clears the acknowledgement.
 */
final class AlertAdministration
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AuditLogger $audit,
    ) {
    }

    public function acknowledge(string $alertId, User $operator, string $note): void
    {
        $note = trim($note);
        if (mb_strlen($note) < 3 || mb_strlen($note) > 1000) {
            throw new DomainRuleViolation('Record what you found or did (3-1000 characters).');
        }
        $this->connection->transactional(function () use ($alertId, $operator, $note): void {
            $alert = \Symfony\Component\Uid\Uuid::isValid($alertId) ? $this->connection->fetchAssociative(
                'SELECT client_id::text AS client_id, metric, window_hours, severity, resolved_at FROM client_alerts WHERE id = ? FOR UPDATE', [$alertId]) : false;
            if (false === $alert) {
                throw new DomainRuleViolation('No such alert.');
            }
            $this->connection->executeStatement(
                'UPDATE client_alerts SET acknowledged_at = now(), acknowledged_by = ?, acknowledgement_note = ? WHERE id = ?',
                [$operator->getId()->toRfc4122(), $note, $alertId]);
            $this->audit->record(AuditActor::user($operator), 'client_alert.acknowledged', 'client', $alert['client_id'], [
                'alert_id' => $alertId, 'metric' => $alert['metric'], 'window_hours' => (int) $alert['window_hours'],
                'severity' => $alert['severity'], 'note' => $note]);
        });
    }
}
