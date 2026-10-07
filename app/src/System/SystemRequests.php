<?php

declare(strict_types=1);

namespace App\System;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\User;
use App\Enum\SystemRequestAction;
use App\Util\Clock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Work the web application asks of the host agent (system_requests, specification 2.11).
 *
 * The web application never runs host commands. An administrator's request is checked,
 * audited and recorded here; the host agent (`smarthostctl prod agent`, a user service on
 * the host) claims it through the console, performs it with the production tooling and
 * records the outcome. Parameters are validated here and again by the agent.
 */
final class SystemRequests
{
    private const DOMAIN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';
    private const SELECTOR = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    public function __construct(private readonly Connection $connection, private readonly AuditLogger $audit)
    {
    }

    /** @param array<string, string> $params */
    public function request(SystemRequestAction $action, array $params, User $user, ?string $note = null): string
    {
        $params = self::validParams($action, $params);
        $note = null === $note ? null : trim($note);
        if (\in_array($action, [SystemRequestAction::DeliveryLiveEnable, SystemRequestAction::DeliveryLiveDisable, SystemRequestAction::DeliveryResume], true)
            && (null === $note || mb_strlen($note) < 3)) {
            throw new DomainRuleViolation('Give a reason (at least 3 characters); it is kept in the audit log.');
        }
        if (null !== $note && ('' === $note || mb_strlen($note) > 1000)) {
            $note = '' === $note ? null : throw new DomainRuleViolation('The note is limited to 1000 characters.');
        }
        if (\in_array($action, [SystemRequestAction::DeliveryPause, SystemRequestAction::DeliveryResume], true)) {
            // The agent applies holds and releases in order: reuse only a pending request that is
            // already the last word (pause, resume, pause must stay three requests).
            $last = $this->connection->fetchAssociative(<<<'SQL'
                SELECT id::text AS id, action FROM system_requests
                 WHERE action IN ('delivery.pause', 'delivery.resume') AND status = 'pending' ORDER BY requested_at DESC LIMIT 1
                SQL);
            if (false !== $last && $last['action'] === $action->value) {
                return (string) $last['id'];
            }
        } elseif (false !== $this->connection->fetchOne(
            "SELECT 1 FROM system_requests WHERE action = ? AND status IN ('pending', 'running')", [$action->value])) {
            throw new DomainRuleViolation('The same request is already waiting for the host agent.');
        }
        $id = Uuid::v7()->toRfc4122();
        $this->connection->transactional(function () use ($id, $action, $params, $user, $note): void {
            $this->connection->insert('system_requests', [
                'id' => $id, 'action' => $action->value, 'params_json' => json_encode($params, \JSON_THROW_ON_ERROR),
                'requested_by_user_id' => $user->getId()->toRfc4122(), 'note' => $note,
                'requested_at' => Clock::now()->format('Y-m-d H:i:s.uP'),
            ]);
            $this->audit->record(AuditActor::user($user), 'system.request.'.$action->value, 'system_request', $id,
                ['params' => $params, 'note' => $note]);
        });

        return $id;
    }

    public function cancel(string $id, User $user): bool
    {
        $done = 1 === $this->connection->executeStatement(
            "UPDATE system_requests SET status = 'cancelled', finished_at = now(), result_summary = 'Cancelled before the host agent claimed it.'
              WHERE id = ? AND status = 'pending'", [$id]);
        if ($done) {
            $this->audit->record(AuditActor::user($user), 'system.request.cancelled', 'system_request', $id);
        }

        return $done;
    }

    /**
     * The host agent claims every pending request (oldest first). The requester's login
     * email travels with the request: delivery controls are audited again by the CLI.
     *
     * @return list<array{id: string, action: string, params: array<string, string>, note: ?string, requested_by: ?string, requested_at: string}>
     */
    public function claim(int $limit = 10): array
    {
        return $this->connection->transactional(function () use ($limit): array {
            $rows = $this->connection->fetchAllAssociative(<<<'SQL'
                UPDATE system_requests r SET status = 'running', started_at = now()
                  FROM (SELECT id FROM system_requests WHERE status = 'pending' ORDER BY requested_at LIMIT ? FOR UPDATE SKIP LOCKED) p
                 WHERE r.id = p.id
                RETURNING r.id::text AS id, r.action, r.params_json, r.note, r.requested_at, r.requested_by_user_id::text AS user_id
                SQL, [$limit], [\Doctrine\DBAL\ParameterType::INTEGER]);
            $out = [];
            foreach ($rows as $r) {
                $email = null === $r['user_id'] ? null : $this->connection->fetchOne('SELECT email FROM users WHERE id = ?', [$r['user_id']]);
                $out[] = ['id' => $r['id'], 'action' => $r['action'], 'params' => json_decode((string) $r['params_json'], true) ?: [],
                    'note' => $r['note'], 'requested_by' => false === $email ? null : $email, 'requested_at' => (string) $r['requested_at']];
            }
            usort($out, static fn (array $a, array $b): int => strcmp($a['requested_at'], $b['requested_at']));

            return $out;
        });
    }

    /** @param array<string, mixed> $result */
    public function finish(string $id, bool $succeeded, string $summary, array $result = []): void
    {
        $updated = $this->connection->executeStatement(<<<'SQL'
            UPDATE system_requests SET status = ?, finished_at = now(), result_summary = ?, result_json = ?
             WHERE id = ? AND status = 'running'
            SQL, [$succeeded ? 'succeeded' : 'failed', mb_substr(Redactor::text($summary), 0, 2000),
                json_encode(Redactor::data($result), \JSON_THROW_ON_ERROR), $id]);
        if (1 !== $updated) {
            throw new DomainRuleViolation("Request $id is not running.");
        }
    }

    /** Requests the agent claimed but never finished (agent crash): failed after $minutes. */
    public function expireStale(int $minutes = 60): int
    {
        return (int) $this->connection->executeStatement(<<<'SQL'
            UPDATE system_requests SET status = 'failed', finished_at = now(),
                   result_summary = 'The host agent did not report back (it may have been restarted); run it again.'
             WHERE status = 'running' AND started_at < now() - make_interval(mins => ?)
            SQL, [$minutes]);
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT r.id::text AS id, r.action, r.params_json, r.status, r.note, r.requested_at, r.started_at, r.finished_at,
                   r.result_summary, u.email AS requested_by
              FROM system_requests r LEFT JOIN users u ON u.id = r.requested_by_user_id
             ORDER BY r.requested_at DESC, r.id DESC LIMIT ?
            SQL, [$limit], [\Doctrine\DBAL\ParameterType::INTEGER]);
    }

    /** @return array<string, mixed>|null the newest request of an action */
    public function latest(SystemRequestAction $action): ?array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT id::text AS id, status, requested_at, finished_at, result_summary, result_json
              FROM system_requests WHERE action = ? ORDER BY requested_at DESC LIMIT 1
            SQL, [$action->value]);

        return false === $row ? null : $row;
    }

    public function pendingCount(): int
    {
        return (int) $this->connection->fetchOne("SELECT count(*) FROM system_requests WHERE status IN ('pending', 'running')");
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, string>
     */
    public static function validParams(SystemRequestAction $action, array $params): array
    {
        return match ($action) {
            SystemRequestAction::DkimGenerate, SystemRequestAction::DkimActivate => (static function () use ($params): array {
                $domain = strtolower(trim((string) ($params['domain'] ?? '')));
                $selector = strtolower(trim((string) ($params['selector'] ?? '')));
                if (1 !== preg_match(self::DOMAIN, $domain) || 1 !== preg_match(self::SELECTOR, $selector)) {
                    throw new DomainRuleViolation('Give a sending domain and a DKIM selector (letters, digits and hyphens, e.g. s2026a).');
                }

                return ['domain' => $domain, 'selector' => $selector];
            })(),
            SystemRequestAction::DiagnosticsRun => isset($params['section']) && '' !== $params['section']
                ? (1 === preg_match('/^[a-z]{2,20}$/', $params['section']) ? ['section' => $params['section']]
                    : throw new DomainRuleViolation('Unknown diagnostics section.'))
                : [],
            default => [],
        };
    }
}
