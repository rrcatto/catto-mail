<?php

declare(strict_types=1);

namespace App\System;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\User;
use App\Enum\SetupStepState;
use App\Util\Clock;
use Doctrine\DBAL\Connection;

/**
 * The administrator setup wizard (specification 2.11). The steps follow the system's real
 * dependencies; each shows the checks of its components, explains them and links to help.
 * Progress is stored (setup_steps), so the wizard can be left and resumed, and it stays
 * available afterwards as System › System setup. A step is never "done" by itself: the
 * administrator marks it done when its checks are satisfactory (or skips it to come back).
 */
final class SetupWizard
{
    /**
     * key => [title, one-line purpose, check components, check key prefixes, help topics]
     * In dependency order.
     */
    public const STEPS = [
        'welcome' => ['Welcome', 'What catto-mail is, its components, and what happens to an address and a message.', [], [], ['overview', 'components', 'architecture']],
        'host' => ['Host', 'The Ubuntu server, Podman, the service user and automatic start.', ['host', 'boot'], [], ['installation', 'reboot']],
        'web_identity' => ['Web identity', 'The public web host name, base URL and administrator address.', [], ['config.', 'dns.a-'], ['installation', 'dns']],
        'smtp_identity' => ['SMTP identity', 'The mail host name, public IPv4, bounce domain and EHLO name.', [], ['config.', 'dns.ptr', 'dns.forward', 'postfix.configuration'], ['ptr', 'postfix']],
        'dns' => ['DNS', 'The DNS records to create, with a live test of each.', ['dns'], [], ['dns', 'ptr', 'spf', 'dkim', 'dmarc']],
        'tls' => ['TLS certificates', 'The HTTPS and SMTP certificates, their expiry and renewal.', ['tls'], [], ['tls']],
        'dkim' => ['DKIM', 'Signing keys per sending domain: generate, publish, test, activate.', ['opendkim'], ['dkim.'], ['dkim']],
        'database' => ['Database', 'PostgreSQL, the schema version and each component\'s rights.', ['database'], ['runtime.database', 'runtime.migrations', 'runtime.grants'], ['database']],
        'web_application' => ['Web application', 'The web application, its API and the tracking endpoints.', ['web', 'tracking'], [], ['web-application', 'tracking']],
        'validator' => ['Email validator', 'The validation worker, its heartbeat and a deterministic test job.', ['validator'], [], ['validator', 'validation']],
        'delivery_daemon' => ['Delivery daemon', 'The sending worker: heartbeat, mode, Postfix queue reconciliation.', ['delivery'], [], ['delivery-daemon', 'delivery-states']],
        'postfix' => ['Postfix', 'The mail server: ports 25 and 587, relay protection, TLS, the queue.', ['postfix'], [], ['postfix']],
        'opendkim' => ['OpenDKIM', 'The signing service: keys present, tables, DNS keys match.', ['opendkim'], ['dkim.'], ['dkim']],
        'webhook_worker' => ['Webhook worker', 'The worker that notifies client systems: heartbeat and a test webhook.', ['webhook'], [], ['webhooks']],
        'backups' => ['Backups', 'Backup schedule, off-host copies and a restore rehearsal.', ['backup'], [], ['backups']],
        'seed_test' => ['Seed delivery test', 'Send to a few addresses you own and follow every stage.', [], [], ['sending', 'delivery-states', 'tracking']],
        'bounce_test' => ['Bounce test', 'Send to a non-existent mailbox at a domain you control and see the bounce come back.', ['bounce'], [], ['bounces', 'suppressions']],
        'reboot_test' => ['Reboot test', 'Reboot the server and confirm everything came back by itself.', ['boot'], [], ['reboot']],
        'readiness' => ['Production readiness', 'One summary of every subsystem. Live delivery stays a separate, deliberate action.', [], [], ['delivery-states', 'emergency-pause']],
    ];

    /** The consolidated readiness dashboard: label => [components, check key prefixes] */
    public const READINESS = [
        'Host' => [['host'], []],
        'Automatic restart' => [['boot'], []],
        'Database' => [['database'], []],
        'Web application' => [['web'], []],
        'Validator' => [['validator'], []],
        'Delivery daemon' => [[], ['app.delivery.heartbeat', 'app.delivery.queue-snapshot', 'delivery.']],
        'Postfix' => [[], ['postfix.configuration', 'postfix.port-25', 'postfix.opendkim', 'postfix.delivery-mode']],
        'OpenDKIM' => [['opendkim'], ['dkim.']],
        'HTTPS/TLS' => [['tls'], []],
        'Inbound SMTP' => [[], ['dns.inbound-smtp', 'postfix.port-25-accepts']],
        'Outbound SMTP' => [[], ['postfix.outbound']],
        'DNS (A, MX)' => [[], ['dns.a-', 'dns.mx']],
        'PTR / FCrDNS' => [[], ['dns.ptr', 'dns.forward']],
        'SPF' => [[], ['dns.spf']],
        'DKIM' => [[], ['dkim.']],
        'DMARC' => [[], ['dns.dmarc']],
        'Bounce processing' => [['bounce'], []],
        'Tracking' => [['tracking'], []],
        'Webhook worker' => [['webhook'], []],
        'Security and exposure' => [['security', 'nginx'], ['exposure.', 'ingress.']],
        'Backup' => [[], ['backup.recent', 'backup.offhost', 'backup.scheduled']],
        'Restore rehearsal' => [[], ['backup.restore-rehearsal']],
    ];

    public function __construct(private readonly Connection $connection, private readonly AuditLogger $audit)
    {
    }

    /** @return array<string, array{key: string, n: int, title: string, purpose: string, state: ?string, note: ?string, updated_at: ?string}> */
    public function steps(): array
    {
        $states = [];
        foreach ($this->connection->fetchAllAssociative('SELECT step_key, state, note, updated_at FROM setup_steps') as $r) {
            $states[$r['step_key']] = $r;
        }
        $out = [];
        $n = 0;
        foreach (self::STEPS as $key => [$title, $purpose]) {
            $out[$key] = ['key' => $key, 'n' => ++$n, 'title' => $title, 'purpose' => $purpose,
                'state' => $states[$key]['state'] ?? null, 'note' => $states[$key]['note'] ?? null, 'updated_at' => $states[$key]['updated_at'] ?? null];
        }

        return $out;
    }

    /** The first step not done or skipped, or null when every step was handled. */
    public function current(): ?string
    {
        foreach ($this->steps() as $key => $s) {
            if (null === $s['state']) {
                return $key;
            }
        }

        return null;
    }

    public function isComplete(): bool
    {
        return 'done' === ($this->steps()['readiness']['state'] ?? null);
    }

    public function set(string $step, ?SetupStepState $state, User $user, ?string $note = null): void
    {
        if (!isset(self::STEPS[$step])) {
            throw new DomainRuleViolation('Unknown setup step.');
        }
        $note = null === $note || '' === trim($note) ? null : mb_substr(trim($note), 0, 1000);
        if (null === $state) {
            $this->connection->delete('setup_steps', ['step_key' => $step]);
        } else {
            $this->connection->executeStatement(<<<'SQL'
                INSERT INTO setup_steps (step_key, state, note, updated_at, updated_by_user_id) VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (step_key) DO UPDATE SET state = EXCLUDED.state, note = EXCLUDED.note,
                    updated_at = EXCLUDED.updated_at, updated_by_user_id = EXCLUDED.updated_by_user_id
                SQL, [$step, $state->value, $note, Clock::now()->format('Y-m-d H:i:s.uP'), $user->getId()->toRfc4122()]);
        }
        $this->audit->record(AuditActor::user($user), 'setup.step_'.($state?->value ?? 'reopened'), 'setup_step', $step, ['note' => $note]);
    }

    /**
     * Checks relevant to a step or readiness row.
     *
     * @param list<string> $components
     * @param list<string> $prefixes
     * @param list<array<string, mixed>> $all
     *
     * @return list<array<string, mixed>>
     */
    public static function select(array $all, array $components, array $prefixes): array
    {
        return array_values(array_filter($all, static function (array $c) use ($components, $prefixes): bool {
            if (\in_array($c['component'], $components, true)) {
                return true;
            }
            foreach ($prefixes as $p) {
                if (str_starts_with((string) $c['check_key'], $p)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /** @param list<array<string, mixed>> $checks */
    public static function worst(array $checks): string
    {
        $worst = 'none';
        foreach ($checks as $c) {
            if ('none' === $worst || SystemChecks::SEVERITY[$c['result']] > SystemChecks::SEVERITY[$worst]) {
                $worst = $c['result'];
            }
        }

        return $worst;
    }
}
