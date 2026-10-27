<?php

declare(strict_types=1);

namespace App\Client;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\ClientLimits;
use App\Util\Clock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Plans (owner decision 2026-10-27): named sets of client limits, defined once, instead of a
 * free-text plan label next to limits set somewhere else.
 *
 *   assign    a client gets a plan: clients.plan names it and the plan's limits are copied into
 *             client_limits (each capped at its installation ceiling, as every limit is). The
 *             enforcement (ClientLimitPolicy, QuotaEnforcer) keeps reading client_limits only;
 *   custom    a client whose limits an operator changed by hand no longer equals its plan: it is
 *             shown as "Custom", and assigning a plan again puts it back on the plan;
 *   update    changing a plan changes the limits of every client on it that is not custom;
 *   delete    only a plan that is not built in and that no client or invitation names.
 *
 * Built in: standard (no client limits: the installation's ceilings) and starter (the plan of
 * every public sign-up). Every change is audited (plan.created, plan.updated, plan.deleted,
 * client.plan_changed).
 */
final class PlanCatalog
{
    public const STANDARD = 'standard';
    public const STARTER = 'starter';
    public const BUILT_IN = [self::STANDARD, self::STARTER];

    public function __construct(
        private readonly Connection $connection,
        private readonly ClientLimitPolicy $policy,
        private readonly ClientLimitAdministration $limits,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Every plan by name, with its limits and how many clients are on it.
     *
     * @return list<array{id: string, name: string, limits: array<string, ?int>, clients: int, built_in: bool, updated_at: string}>
     */
    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.*, (SELECT count(*) FROM clients c WHERE c.plan = p.id) AS clients FROM plans p ORDER BY lower(p.name)');

        return array_map(fn (array $r): array => $this->present($r), $rows);
    }

    /** @return array{id: string, name: string, limits: array<string, ?int>, clients: int, built_in: bool, updated_at: string}|null */
    public function find(string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT p.*, (SELECT count(*) FROM clients c WHERE c.plan = p.id) AS clients FROM plans p WHERE p.id = ?', [$id]);

        return false === $row ? null : $this->present($row);
    }

    /** @return array<string, string> id => name, for a choice of plan */
    public function choices(): array
    {
        $out = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, name FROM plans ORDER BY lower(name)') as $r) {
            $out[(string) $r['id']] = (string) $r['name'];
        }

        return $out;
    }

    public function name(string $id): string
    {
        $name = $this->connection->fetchOne('SELECT name FROM plans WHERE id = ?', [$id]);

        return false === $name ? $id : (string) $name;
    }

    /**
     * The plan's limits as a client gets them: each capped at its installation ceiling.
     *
     * @return array<string, ?int> every client_limits column
     */
    public function effectiveLimits(string $id): array
    {
        $plan = $this->find($id) ?? throw new DomainRuleViolation('Choose one of the plans.');
        $out = [];
        foreach ($plan['limits'] as $column => $value) {
            $ceiling = $this->policy->ceiling($column);
            $out[$column] = null === $value || null === $ceiling ? $value : min($value, $ceiling);
        }

        return $out;
    }

    /** Whether the client's limits differ from its plan's (an operator changed them by hand). */
    public function isCustom(Client $client): bool
    {
        if (null === $this->find($client->getPlan())) {
            return true;
        }

        return $this->policy->clientLimits($client) !== $this->effectiveLimits($client->getPlan());
    }

    /**
     * @param array<string, ?int> $limits client_limits column => value (null = no limit)
     *
     * @return string the new plan's id
     */
    public function create(string $name, array $limits, AuditActor $actor): string
    {
        $name = self::validName($name);
        $limits = $this->validLimits($limits);

        return $this->connection->transactional(function () use ($name, $limits, $actor): string {
            $this->assertNameFree($name, null);
            $id = $this->freeId($name);
            $this->connection->insert('plans', ['id' => $id, 'name' => $name] + $limits);
            $this->audit->record($actor, 'plan.created', 'plan', $id, ['name' => $name, 'limits' => $limits]);

            return $id;
        });
    }

    /**
     * Changes a plan, and the limits of every client on it that is not custom.
     *
     * @param array<string, ?int> $limits
     *
     * @return int how many clients' limits changed with it
     */
    public function update(string $id, string $name, array $limits, AuditActor $actor): int
    {
        $plan = $this->find($id) ?? throw new DomainRuleViolation('That plan does not exist.');
        $name = self::validName($name);
        $limits = $this->validLimits($limits);
        if ($name === $plan['name'] && $limits === $plan['limits']) {
            return 0;
        }
        $before = $this->effectiveLimits($id);

        return $this->connection->transactional(function () use ($id, $plan, $name, $limits, $before, $actor): int {
            $this->assertNameFree($name, $id);
            $this->connection->executeStatement('SELECT 1 FROM plans WHERE id = ? FOR UPDATE', [$id]);
            $this->connection->update('plans', ['name' => $name, 'updated_at' => Clock::now()->format('Y-m-d H:i:s.uP')] + $limits, ['id' => $id]);
            $after = $this->effectiveLimits($id);
            $moved = 0;
            if ($after !== $before) {
                foreach ($this->clientsOn($id) as $client) {
                    if ($this->policy->clientLimits($client) === $before) {
                        $this->limits->set($client, $after, $actor, \sprintf('plan %s changed', $name));
                        ++$moved;
                    }
                }
            }
            $this->audit->record($actor, 'plan.updated', 'plan', $id, ['before' => ['name' => $plan['name'], 'limits' => $plan['limits']],
                'after' => ['name' => $name, 'limits' => $limits], 'clients_changed' => $moved]);

            return $moved;
        });
    }

    public function delete(string $id, AuditActor $actor): void
    {
        $plan = $this->find($id) ?? throw new DomainRuleViolation('That plan does not exist.');
        if (\in_array($id, self::BUILT_IN, true)) {
            throw new DomainRuleViolation(\sprintf('%s is built in and cannot be deleted.', $plan['name']));
        }
        $this->connection->transactional(function () use ($id, $plan, $actor): void {
            if (false !== $this->connection->fetchOne('SELECT 1 FROM clients WHERE plan = ? UNION ALL SELECT 1 FROM client_invitations WHERE plan = ? LIMIT 1', [$id, $id])) {
                throw new DomainRuleViolation(\sprintf('%s is still the plan of a client or an invitation: move them to another plan first.', $plan['name']));
            }
            $this->connection->delete('plans', ['id' => $id]);
            $this->audit->record($actor, 'plan.deleted', 'plan', $id, ['name' => $plan['name']]);
        });
    }

    /** Puts a client on a plan: its limits become the plan's (audited as client.plan_changed and client.limits_changed). */
    public function assign(Client $client, string $id, AuditActor $actor): void
    {
        $limits = $this->effectiveLimits($id);
        $from = $client->getPlan();
        $this->connection->transactional(function () use ($client, $id, $from, $limits, $actor): void {
            $this->connection->executeStatement('UPDATE clients SET plan = ? WHERE id = ?', [$id, $client->getId()->toRfc4122()]);
            $client->setPlan($id);
            $this->limits->set($client, $limits, $actor, \sprintf('plan %s', $this->name($id)));
            if ($from !== $id) {
                $this->audit->record($actor, 'client.plan_changed', 'client', $client->getId()->toRfc4122(), ['from' => $from, 'to' => $id]);
            }
        });
    }

    /**
     * Limits typed into a form: each column's text, empty for no limit.
     *
     * @param array<array-key, mixed> $input
     *
     * @return array<string, ?int>
     */
    public static function limitsFromInput(array $input): array
    {
        $out = [];
        foreach (ClientLimits::FIELDS as $column) {
            $raw = \is_string($input[$column] ?? null) ? trim(str_replace([',', ' '], '', $input[$column])) : '';
            if ('' !== $raw && 1 !== preg_match('/^\d{1,12}$/', $raw)) {
                throw new DomainRuleViolation(\sprintf('%s must be a whole number, or empty for no limit.', \App\Dashboard\Labels::label($column, 'limit')));
            }
            $out[$column] = '' === $raw ? null : (int) $raw;
        }

        return $out;
    }

    /** @return list<Client> */
    private function clientsOn(string $id): array
    {
        $clients = [];
        foreach ($this->connection->fetchFirstColumn('SELECT id::text FROM clients WHERE plan = ? ORDER BY id', [$id]) as $clientId) {
            $client = $this->em->find(Client::class, Uuid::fromString((string) $clientId));
            if (null !== $client) {
                $clients[] = $client;
            }
        }

        return $clients;
    }

    /**
     * @param array<string, ?int> $limits
     *
     * @return array<string, ?int> every column, in order
     */
    private function validLimits(array $limits): array
    {
        $out = [];
        foreach (ClientLimits::FIELDS as $column) {
            $out[$column] = $limits[$column] ?? null;
        }
        $this->limits->validate($out);

        return $out;
    }

    private static function validName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ('' === $name || mb_strlen($name) > 100 || 1 === preg_match('/\p{C}/u', $name)) {
            throw new DomainRuleViolation('Give the plan a name of at most 100 characters.');
        }

        return $name;
    }

    private function assertNameFree(string $name, ?string $except): void
    {
        $taken = $this->connection->fetchOne('SELECT id FROM plans WHERE lower(name) = lower(?)', [$name]);
        if (false !== $taken && $taken !== $except) {
            throw new DomainRuleViolation(\sprintf('There is already a plan called %s.', $name));
        }
    }

    /** A key made from the name: lower case, letters, digits and hyphens, unique. */
    private function freeId(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $name))), '-');
        $base = substr(1 === preg_match('/^[a-z]/', $base) ? $base : 'plan-'.$base, 0, 34);
        $base = rtrim('' === trim($base, '-') ? 'plan' : $base, '-');
        if (\strlen($base) < 2) {
            $base = 'plan-'.$base;
        }
        $id = $base;
        for ($n = 2; false !== $this->connection->fetchOne('SELECT 1 FROM plans WHERE id = ?', [$id]); ++$n) {
            $id = $base.'-'.$n;
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: string, name: string, limits: array<string, ?int>, clients: int, built_in: bool, updated_at: string}
     */
    private function present(array $row): array
    {
        $limits = [];
        foreach (ClientLimits::FIELDS as $column) {
            $limits[$column] = null === $row[$column] ? null : (int) $row[$column];
        }

        return ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'limits' => $limits, 'clients' => (int) $row['clients'],
            'built_in' => \in_array($row['id'], self::BUILT_IN, true), 'updated_at' => (string) $row['updated_at']];
    }
}
