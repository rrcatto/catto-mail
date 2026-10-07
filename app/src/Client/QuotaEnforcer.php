<?php

declare(strict_types=1);

namespace App\Client;

use App\Api\ApiProblem;
use App\Entity\Client;
use App\Enum\QuotaMetric;
use App\Enum\QuotaPeriod;
use App\Util\Clock;
use Doctrine\DBAL\Connection;

/**
 * Concurrency-safe per-client quotas (Phase 9, specification 2.10 `client_limits`).
 *
 * admit() runs inside the transaction that admits the work (validation job creation,
 * send job creation, a recipient batch). For the UTC day and the UTC month it adds the
 * amount to client_quota_usage with one statement,
 *
 *   INSERT ... ON CONFLICT (client_id, metric, period, period_start)
 *   DO UPDATE SET used = client_quota_usage.used + EXCLUDED.used RETURNING used
 *
 * which locks the counter row: concurrent admissions of the same client serialise on it,
 * each sees the committed total of the others, and a request that is refused (or fails
 * later) rolls its increment back with the rest of its transaction. When the new total
 * exceeds the client's quota for that period, the work is refused with 429
 * quota-exceeded and Retry-After = the time until the period resets. Counters are kept
 * even where no quota is set, so a limit set later applies to the current period, and so
 * the counters can be reconciled against the jobs and batches (App\Usage\UsageReconciliation).
 *
 * Idempotent replays never reach admit(): they return before any work is admitted.
 */
final class QuotaEnforcer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClientLimitPolicy $policy,
    ) {
    }

    public function admit(Client $client, QuotaMetric $metric, int $amount, ?\DateTimeImmutable $at = null): void
    {
        if ($amount < 1) {
            return;
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Quota admission must run in the transaction that admits the work.');
        }
        $at = ($at ?? Clock::now())->setTimezone(new \DateTimeZone('UTC'));
        foreach (QuotaPeriod::cases() as $period) {
            [$start, $end] = self::period($period, $at);
            $used = (int) $this->connection->fetchOne(<<<'SQL'
                INSERT INTO client_quota_usage (client_id, metric, period, period_start, used, updated_at)
                VALUES (?, ?, ?, ?, ?, now())
                ON CONFLICT (client_id, metric, period, period_start)
                DO UPDATE SET used = client_quota_usage.used + EXCLUDED.used, updated_at = now()
                RETURNING used
                SQL, [$client->getId()->toRfc4122(), $metric->value, $period->value, $start->format('Y-m-d'), $amount]);
            $limit = $this->policy->quota($client, $metric, $period);
            if (null !== $limit && $used > $limit) {
                throw ApiProblem::quotaExceeded([
                    'metric' => $metric->value, 'period' => $period->value, 'limit' => $limit,
                    'used' => $used - $amount, 'requested' => $amount, 'resets_at' => Clock::rfc3339($end) ?? '',
                ], $end->getTimestamp() - $at->getTimestamp());
            }
        }
    }

    /**
     * Current usage of every metric and period of the client (for the dashboards).
     *
     * @return array<string, int> "metric|period" => used in the current period
     */
    public function currentUsage(Client|string $client, ?\DateTimeImmutable $at = null): array
    {
        $id = $client instanceof Client ? $client->getId()->toRfc4122() : $client;
        $at = ($at ?? Clock::now())->setTimezone(new \DateTimeZone('UTC'));
        $out = [];
        foreach (QuotaMetric::cases() as $metric) {
            foreach (QuotaPeriod::cases() as $period) {
                $out[$metric->value.'|'.$period->value] = 0;
            }
        }
        $rows = $this->connection->fetchAllAssociative(
            "SELECT metric, period, used FROM client_quota_usage WHERE client_id = ? AND ((period = 'day' AND period_start = ?) OR (period = 'month' AND period_start = ?))",
            [$id, self::period(QuotaPeriod::Day, $at)[0]->format('Y-m-d'), self::period(QuotaPeriod::Month, $at)[0]->format('Y-m-d')]);
        foreach ($rows as $r) {
            $out[$r['metric'].'|'.$r['period']] = (int) $r['used'];
        }

        return $out;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} UTC start (inclusive) and end (exclusive) */
    public static function period(QuotaPeriod $period, \DateTimeImmutable $at): array
    {
        $at = $at->setTimezone(new \DateTimeZone('UTC'));
        $start = QuotaPeriod::Day === $period ? $at->setTime(0, 0) : $at->modify('first day of this month')->setTime(0, 0);

        return [$start, QuotaPeriod::Day === $period ? $start->modify('+1 day') : $start->modify('+1 month')];
    }
}
