<?php

declare(strict_types=1);

namespace App\System;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\User;
use App\Util\Clock;
use Doctrine\DBAL\Connection;

/**
 * Settings changed in the dashboard (setting_overrides): each row takes precedence over the same
 * variable in infra/.env without changing the file. Every change needs a reason and is audited
 * with the old and new value; it takes effect when the configuration is applied (the host agent's
 * settings.apply request in production, `smarthostctl settings-apply` in development).
 */
final class SettingOverrides
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SettingCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string, array{value: string, reason: string, updated_at: string, updated_by: ?string}> */
    public function all(): array
    {
        $out = [];
        foreach ($this->connection->fetchAllAssociative(<<<'SQL'
            SELECT o.name, o.value, o.reason, o.updated_at, u.email AS updated_by
              FROM setting_overrides o LEFT JOIN users u ON u.id = o.updated_by_user_id ORDER BY o.name
            SQL) as $r) {
            $out[(string) $r['name']] = ['value' => (string) $r['value'], 'reason' => (string) $r['reason'],
                'updated_at' => (string) $r['updated_at'], 'updated_by' => null === $r['updated_by'] ? null : (string) $r['updated_by']];
        }

        return $out;
    }

    /** @return array<string, string> name => value */
    public function values(): array
    {
        return array_map(static fn (array $o): string => $o['value'], $this->all());
    }

    /**
     * Set or clear several settings at once. A null or empty value clears the override (the
     * infra/.env value applies again), except for a setting where empty is a value of its own.
     *
     * @param array<string, ?string> $changes
     *
     * @return list<string> the names that changed
     */
    public function change(array $changes, string $reason, User $user, string $environment): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
            throw new DomainRuleViolation('Give a reason (3 to 1000 characters); it is kept in the audit log.');
        }
        $current = $this->values();
        $errors = [];
        $plan = [];
        foreach ($changes as $name => $value) {
            $value = null === $value ? null : trim($value);
            if (!$this->catalog->has($name)) {
                $errors[] = "$name: this setting cannot be changed in the dashboard.";
                continue;
            }
            if (null !== $value && '' === $value && !($this->catalog->all()[$name]['empty'] ?? false)) {
                $value = null;
            }
            if (null !== $value && null !== ($why = $this->catalog->error($name, $value, $environment))) {
                $errors[] = "$name: $why";
                continue;
            }
            if (($current[$name] ?? null) !== $value) {
                $plan[$name] = $value;
            }
        }
        if ([] !== $errors) {
            throw new DomainRuleViolation(implode(' ', $errors));
        }
        if ([] === $plan) {
            return [];
        }
        $this->connection->transactional(function () use ($plan, $current, $reason, $user): void {
            $now = Clock::now()->format('Y-m-d H:i:s.uP');
            foreach ($plan as $name => $value) {
                if (null === $value) {
                    $this->connection->delete('setting_overrides', ['name' => $name]);
                } else {
                    $this->connection->executeStatement(<<<'SQL'
                        INSERT INTO setting_overrides (name, value, reason, updated_at, updated_by_user_id) VALUES (?, ?, ?, ?, ?)
                        ON CONFLICT (name) DO UPDATE SET value = EXCLUDED.value, reason = EXCLUDED.reason,
                            updated_at = EXCLUDED.updated_at, updated_by_user_id = EXCLUDED.updated_by_user_id
                        SQL, [$name, $value, $reason, $now, $user->getId()->toRfc4122()]);
                }
                $this->audit->record(AuditActor::user($user), null === $value ? 'setting.override.cleared' : 'setting.override.set',
                    'setting', $name, ['old' => $current[$name] ?? null, 'new' => $value, 'reason' => $reason]);
            }
        });

        return array_keys($plan);
    }
}
