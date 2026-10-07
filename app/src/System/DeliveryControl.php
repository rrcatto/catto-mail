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

/**
 * The installation-wide delivery controls of the web application (specification 2.11).
 *
 * "Stop sending email now" sets delivery_controls.emergency_stop: the delivery daemon
 * reads it every few seconds and stops starting submissions (send jobs keep their leases
 * and resume where they stopped). It also asks the host agent to hold the Postfix queue,
 * the same audited pause as `smarthostctl prod pause`, so mail Postfix already accepted
 * stays queued. Lifting the stop needs SYSTEM.DELIVERY.CONTROL and a reason, like every
 * delivery control.
 *
 * Modes shown to the operator:
 *   LIVE    live delivery enabled, nothing paused: mail goes to the Internet.
 *   HELD    production before live activation: send jobs wait; nothing goes out.
 *   PAUSED  the emergency stop or the pause is in force: nothing new is submitted, the
 *           Postfix queue is held.
 *   STOPPED the delivery daemon is not running (no recent heartbeat): nothing is sent,
 *           and nothing is processed (bounces wait in the spool).
 */
final class DeliveryControl
{
    public const HEARTBEAT_STALE_SECONDS = 120;

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditLogger $audit,
        private readonly SystemRequests $requests,
    ) {
    }

    public function emergencyStop(User $user, string $note): void
    {
        $note = self::note($note);
        $this->connection->transactional(function () use ($user, $note): void {
            $this->set(true, $user, $note);
            $this->audit->record(AuditActor::user($user), 'delivery.outbound_paused', 'system', null, ['note' => $note, 'via' => 'dashboard']);
        });
        // The Postfix queue hold follows through the host agent (a duplicate pending pause is fine).
        $this->requests->request(SystemRequestAction::DeliveryPause, [], $user, $note);
    }

    public function resume(User $user, string $note): void
    {
        $note = self::note($note);
        $this->connection->transactional(function () use ($user, $note): void {
            $this->set(false, $user, $note);
            $this->audit->record(AuditActor::user($user), 'delivery.outbound_resumed', 'system', null, ['note' => $note, 'via' => 'dashboard']);
        });
        $this->requests->request(SystemRequestAction::DeliveryResume, [], $user, $note);
    }

    /** The console form, used by `smarthostctl prod pause/resume` (the CLI audits the operator itself). */
    public function setFromConsole(bool $stopped, ?User $user, string $note): void
    {
        $this->set($stopped, $user, self::note($note));
    }

    public function isStopped(): bool
    {
        return true === (bool) $this->connection->fetchOne('SELECT emergency_stop FROM delivery_controls WHERE id = 1');
    }

    /**
     * @return array{mode: string, explanation: string, emergency_stop: bool, stop_changed_at: ?string, stop_note: ?string,
     *               live_delivery: ?bool, heartbeat_age: ?int, queue: ?array<string, int>, queue_snapshot_age: ?int, global_rate_per_minute: ?int}
     */
    public function status(): array
    {
        $control = $this->connection->fetchAssociative('SELECT emergency_stop, changed_at, note FROM delivery_controls WHERE id = 1') ?: null;
        $hb = $this->connection->fetchAssociative(<<<'SQL'
            SELECT live_delivery, send_work_held, outbound_paused, extract(epoch FROM now() - last_seen_at)::bigint AS age, global_rate_per_minute,
                   queue_active, queue_deferred, queue_hold, queue_incoming,
                   extract(epoch FROM now() - queue_snapshot_at)::bigint AS snapshot_age
              FROM delivery_heartbeats WHERE stopped_at IS NULL ORDER BY last_seen_at DESC LIMIT 1
            SQL) ?: null;
        $stop = null !== $control && (bool) $control['emergency_stop'];
        $age = null === $hb ? null : (int) $hb['age'];
        if (null === $hb || $age > self::HEARTBEAT_STALE_SECONDS) {
            $mode = 'STOPPED';
            $why = 'The delivery daemon is not running ('.(null === $age ? 'it has never reported' : 'no heartbeat for '.self::duration($age)).'): nothing is sent, and bounces wait to be processed.';
        } elseif ($stop || (bool) $hb['outbound_paused']) {
            $mode = 'PAUSED';
            $why = 'The emergency stop is in force: nothing new is submitted and mail already in the Postfix queue is held.';
        } elseif (!(bool) $hb['live_delivery']) {
            $mode = 'HELD';
            $why = 'Live delivery has not been activated: send jobs wait and nothing goes to the Internet (except dashboard sign-in mail).';
        } else {
            $mode = 'LIVE';
            $why = 'Live delivery is enabled: mail is sent to recipients on the Internet within the configured rate limits.';
        }
        $queue = null === $hb || null === $hb['queue_active'] ? null : [
            'active' => (int) $hb['queue_active'], 'deferred' => (int) $hb['queue_deferred'],
            'hold' => (int) $hb['queue_hold'], 'incoming' => (int) $hb['queue_incoming']];

        return ['mode' => $mode, 'explanation' => $why, 'emergency_stop' => $stop,
            'stop_changed_at' => null === $control ? null : (string) $control['changed_at'],
            'stop_note' => null === $control ? null : $control['note'],
            'live_delivery' => null === $hb ? null : (bool) $hb['live_delivery'], 'heartbeat_age' => $age,
            'queue' => $queue, 'queue_snapshot_age' => null === $hb || null === $hb['snapshot_age'] ? null : (int) $hb['snapshot_age'],
            'global_rate_per_minute' => null === $hb ? null : (int) $hb['global_rate_per_minute']];
    }

    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => $seconds.' s',
            $seconds < 7200 => intdiv($seconds, 60).' min',
            $seconds < 172800 => intdiv($seconds, 3600).' h',
            default => intdiv($seconds, 86400).' days',
        };
    }

    private function set(bool $stopped, ?User $user, string $note): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO delivery_controls (id, emergency_stop, changed_at, changed_by_user_id, note) VALUES (1, ?, ?, ?, ?)
            ON CONFLICT (id) DO UPDATE SET emergency_stop = EXCLUDED.emergency_stop, changed_at = EXCLUDED.changed_at,
                changed_by_user_id = EXCLUDED.changed_by_user_id, note = EXCLUDED.note
            SQL, [$stopped, Clock::now()->format('Y-m-d H:i:s.uP'), $user?->getId()->toRfc4122(), $note],
            [\Doctrine\DBAL\ParameterType::BOOLEAN]);
    }

    private static function note(string $note): string
    {
        $note = trim($note);
        if (mb_strlen($note) < 3 || mb_strlen($note) > 1000) {
            throw new DomainRuleViolation('Give a reason (3 to 1000 characters); it is kept in the audit log.');
        }

        return $note;
    }
}
