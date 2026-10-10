<?php

declare(strict_types=1);

namespace App\System;

use App\Enum\SystemCheckResult;
use App\Enum\SystemCheckSource;
use App\Enum\SystemComponent;
use App\Util\Clock;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The latest result of every system check and its meaningful history (system_checks,
 * system_check_runs; specification 2.11). A result enters the history when it was
 * explicitly requested, when it differs from the previous result, or as the first
 * scheduled result of the check on a day of the installation's zone, so the history stays small and readable.
 */
final class SystemChecks
{
    /** Worst first: what a component shows when its checks disagree. */
    public const SEVERITY = ['fail' => 4, 'warn' => 3, 'skipped' => 2, 'info' => 1, 'pass' => 0];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<array{key: string, component: string, title: string, result: string, summary: string,
     *                   detail?: array<string, mixed>, duration_ms?: ?int}> $checks
     *
     * @return int the number of checks recorded
     */
    public function record(array $checks, SystemCheckSource $source, bool $requested): int
    {
        $n = 0;
        foreach ($checks as $c) {
            $key = strtolower((string) ($c['key'] ?? ''));
            $component = SystemComponent::tryFrom((string) ($c['component'] ?? ''));
            $result = SystemCheckResult::tryFrom(strtolower((string) ($c['result'] ?? '')));
            if (1 !== preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/', $key) || null === $component || null === $result) {
                continue; // a malformed report line is ignored, never stored half-way
            }
            $title = mb_substr(trim((string) ($c['title'] ?? $key)), 0, 200) ?: $key;
            $summary = mb_substr(Redactor::text(trim((string) ($c['summary'] ?? ''))), 0, 2000);
            $detail = json_encode(Redactor::data(\is_array($c['detail'] ?? null) ? $c['detail'] : []), \JSON_THROW_ON_ERROR);
            $ms = isset($c['duration_ms']) && is_numeric($c['duration_ms']) ? max(0, (int) $c['duration_ms']) : null;
            $now = Clock::now()->format('Y-m-d H:i:s.uP');
            $this->connection->transactional(function () use ($key, $component, $result, $title, $summary, $detail, $ms, $now, $source, $requested): void {
                $previous = $this->connection->fetchAssociative('SELECT result, ran_at FROM system_checks WHERE check_key = ? FOR UPDATE', [$key]);
                $changed = false === $previous || $previous['result'] !== $result->value;
                $this->connection->executeStatement(<<<'SQL'
                    INSERT INTO system_checks (check_key, component, title, result, summary, detail_json, duration_ms, source, ran_at, changed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT (check_key) DO UPDATE SET component = EXCLUDED.component, title = EXCLUDED.title, result = EXCLUDED.result,
                        summary = EXCLUDED.summary, detail_json = EXCLUDED.detail_json, duration_ms = EXCLUDED.duration_ms,
                        source = EXCLUDED.source, ran_at = EXCLUDED.ran_at,
                        changed_at = CASE WHEN system_checks.result = EXCLUDED.result THEN system_checks.changed_at ELSE EXCLUDED.ran_at END
                    SQL, [$key, $component->value, $title, $result->value, $summary, $detail, $ms, $source->value, $now, $now],
                    [6 => null === $ms ? ParameterType::NULL : ParameterType::INTEGER]);
                $trigger = $requested ? 'requested' : ($changed ? 'changed' : null);
                if (null === $trigger && (int) $this->connection->fetchOne(
                    "SELECT count(*) FROM system_check_runs WHERE check_key = ? AND ran_at >= date_trunc('day', now() AT TIME ZONE CAST(? AS text)) AT TIME ZONE CAST(? AS text)",
                    [$key, Clock::zone()->getName(), Clock::zone()->getName()]) === 0) {
                    $trigger = 'scheduled';
                }
                if (null !== $trigger) {
                    $this->connection->insert('system_check_runs', [
                        'id' => Uuid::v7()->toRfc4122(), 'check_key' => $key, 'component' => $component->value, 'title' => $title,
                        'result' => $result->value, 'summary' => $summary, 'duration_ms' => $ms, 'source' => $source->value,
                        'run_trigger' => $trigger, 'ran_at' => $now,
                    ], ['duration_ms' => null === $ms ? ParameterType::NULL : ParameterType::INTEGER]);
                }
            });
            ++$n;
        }

        return $n;
    }

    /**
     * After a complete host-agent report (host checks and every preflight section): removes the
     * agent checks it no longer contains, e.g. the DNS checks of a host name that was changed.
     * Their history stays in system_check_runs. Never acts on an empty report.
     *
     * @param list<string> $reportedKeys
     */
    public function retireAgentChecksExcept(array $reportedKeys): int
    {
        $keys = array_values(array_unique(array_map('strtolower', $reportedKeys)));
        if ([] === $keys) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'DELETE FROM system_checks WHERE source = ? AND check_key NOT IN (?)',
            [SystemCheckSource::Agent->value, $keys], [ParameterType::STRING, ArrayParameterType::STRING]);
    }

    /** @return list<array<string, mixed>> latest results, optionally of some components */
    public function latest(array $components = []): array
    {
        $sql = 'SELECT check_key, component, title, result, summary, detail_json, duration_ms, source, ran_at, changed_at FROM system_checks';
        $params = $types = [];
        if ([] !== $components) {
            $sql .= ' WHERE component IN (?)';
            $params[] = array_values($components);
            $types[] = \Doctrine\DBAL\ArrayParameterType::STRING;
        }
        $rows = $this->connection->fetchAllAssociative($sql.' ORDER BY component, check_key', $params, $types);
        foreach ($rows as $i => $r) {
            $rows[$i]['detail'] = json_decode((string) $r['detail_json'], true) ?: [];
            $rows[$i]['help'] = CheckCatalog::explain((string) $r['check_key'], (string) $r['component']);
        }

        return $rows;
    }

    /** @return array<string, array{result: string, counts: array<string, int>, ran_at: ?string}> per component */
    public function byComponent(): array
    {
        $out = [];
        foreach (SystemComponent::cases() as $c) {
            $out[$c->value] = ['result' => 'none', 'counts' => [], 'ran_at' => null];
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT component, result, count(*) AS n, max(ran_at) AS ran_at FROM system_checks GROUP BY component, result') as $r) {
            $o = &$out[$r['component']];
            $o['counts'][$r['result']] = (int) $r['n'];
            if ('none' === $o['result'] || self::SEVERITY[$r['result']] > self::SEVERITY[$o['result']]) {
                $o['result'] = $r['result'];
            }
            $o['ran_at'] = max((string) $o['ran_at'], (string) $r['ran_at']) ?: null;
            unset($o);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function history(?string $checkKey, ?string $component, ?string $result, int $limit = 200): array
    {
        $where = ['true'];
        $params = [];
        foreach (['check_key' => $checkKey, 'component' => $component, 'result' => $result] as $column => $value) {
            if (null !== $value && '' !== $value) {
                $where[] = "$column = ?";
                $params[] = $value;
            }
        }
        $params[] = $limit;

        return $this->connection->fetchAllAssociative('SELECT check_key, component, title, result, summary, duration_ms, source, run_trigger, ran_at
            FROM system_check_runs WHERE '.implode(' AND ', $where).' ORDER BY ran_at DESC, id DESC LIMIT ?', $params,
            [\count($params) - 1 => ParameterType::INTEGER]);
    }

    /** @return list<array<string, mixed>> each check's last run, for the history overview */
    public function lastRuns(): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT c.check_key, c.component, c.title, c.result, c.summary, c.duration_ms, c.ran_at, c.changed_at,
                   (SELECT count(*) FROM system_check_runs r WHERE r.check_key = c.check_key) AS runs
              FROM system_checks c ORDER BY c.component, c.check_key
            SQL);
    }

    public function get(string $checkKey): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM system_checks WHERE check_key = ?', [$checkKey]);

        return false === $row ? null : $row + ['detail' => json_decode((string) $row['detail_json'], true) ?: []];
    }
}
