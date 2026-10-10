<?php

declare(strict_types=1);

namespace App\Dashboard;

/**
 * Figures and chart series shared by the operator and client overviews, from the per-job
 * counters of a period (OperatorReadModel::trends, ClientReadModel::trends): KPI cards against
 * the previous period, mail flow per bucket and validation results. Charts are drawn by
 * assets/controllers/chart_controller.js.
 */
final class TrendCharts
{
    /** The message statuses the delivery daemon counts per send job (send_jobs.summary_counts_json). */
    public const STATUSES = ['created', 'queued', 'submitted', 'deferred', 'outcome_unknown', 'remote_accepted', 'soft_bounced', 'hard_bounced', 'complained', 'failed', 'suppressed'];

    /** The SELECT list summing every status counter of a group of send jobs. */
    public static function countersSql(): string
    {
        return implode(', ', array_map(static fn (string $s): string => "COALESCE(sum((summary_counts_json->>'$s')::bigint), 0) AS $s", self::STATUSES));
    }

    /**
     * Rows of (bucket, status counters) from the period and the previous one, as the counters of
     * each bucket of the period (zero-filled), their total and the previous period's total.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{buckets: array<string, array<string, int>>, current: array<string, int>, previous: array<string, int>}
     */
    public static function bucketise(array $rows, OverviewPeriod $p): array
    {
        $zero = array_fill_keys(self::STATUSES, 0);
        $buckets = array_fill_keys($p->bucketKeys(), $zero);
        $current = $previous = $zero;
        $start = $p->since->format('Y-m-d H:00');
        foreach ($rows as $row) {
            $counts = array_map('intval', array_intersect_key($row, $zero));
            if (isset($buckets[$row['bucket']])) {
                $buckets[$row['bucket']] = $counts;
                foreach ($counts as $k => $v) {
                    $current[$k] += $v;
                }
            } elseif ($row['bucket'] < $start) {
                foreach ($counts as $k => $v) {
                    $previous[$k] += $v;
                }
            }
        }

        return ['buckets' => $buckets, 'current' => $current, 'previous' => $previous];
    }

    /** @return array{messages: int, reached: int, accepted: int, hard: int, complained: int, deferred_soft: int, suppressed: int} */
    public static function totals(array $c): array
    {
        $reached = $c['submitted'] + $c['deferred'] + $c['outcome_unknown'] + $c['remote_accepted'] + $c['soft_bounced'] + $c['hard_bounced'] + $c['complained'];

        return ['messages' => array_sum($c), 'reached' => $reached, 'accepted' => $c['remote_accepted'], 'hard' => $c['hard_bounced'],
            'complained' => $c['complained'], 'deferred_soft' => $c['deferred'] + $c['soft_bounced'], 'suppressed' => $c['suppressed']];
    }

    public static function rate(int $part, int $of): ?float
    {
        return $of > 0 ? 100.0 * $part / $of : null;
    }

    /** @return list<array<string, mixed>> */
    public static function kpis(array $t, OverviewPeriod $p): array
    {
        $now = self::totals($t['current']);
        $before = self::totals($t['previous']);
        $series = array_map(self::totals(...), array_values($t['buckets']));
        $labels = array_map($p->bucketLabel(...), array_keys($t['buckets']));
        $vs = 'vs the previous '.$p->label;
        $pt = static function (?float $a, ?float $b, bool $upIsGood): array {
            if (null === $a || null === $b) {
                return ['text' => 'no comparison', 'dir' => 'flat', 'tone' => 'neutral'];
            }
            $d = round($a - $b, 2);
            $dir = $d > 0 ? 'up' : ($d < 0 ? 'down' : 'flat');

            return ['text' => ($d > 0 ? '+' : ($d < 0 ? '−' : '±')).number_format(abs($d), 2).' pt', 'dir' => $dir,
                'tone' => 'flat' === $dir ? 'neutral' : ((('up' === $dir) === $upIsGood) ? 'good' : 'bad')];
        };
        $change = $before['messages'] > 0 ? 100.0 * ($now['messages'] - $before['messages']) / $before['messages'] : null;
        $fmtRate = static fn (?float $r, int $decimals): string => null === $r ? '—' : number_format($r, $decimals).' %';
        $chart = static fn (array $values, string $format): array => ['labels' => $labels, 'values' => $values, 'format' => $format];

        return [
            ['label' => 'Messages', 'value' => number_format($now['messages']), 'note' => $vs,
                'delta' => null === $change ? ['text' => 'no comparison', 'dir' => 'flat', 'tone' => 'neutral']
                    : ['text' => ($change >= 0 ? '+' : '−').number_format(abs($change), 1).' %', 'dir' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'), 'tone' => 'neutral'],
                'chart' => $chart(array_column($series, 'messages'), 'count')],
            ['label' => 'Remote accepted', 'value' => $fmtRate(self::rate($now['accepted'], $now['reached']), 1), 'note' => 'of messages that reached Postfix',
                'delta' => $pt(self::rate($now['accepted'], $now['reached']), self::rate($before['accepted'], $before['reached']), true),
                'chart' => $chart(array_map(static fn (array $s): ?float => self::rate($s['accepted'], $s['reached']), $series), 'percent')],
            ['label' => 'Hard-bounce rate', 'value' => $fmtRate(self::rate($now['hard'], $now['reached']), 2), 'note' => $vs,
                'delta' => $pt(self::rate($now['hard'], $now['reached']), self::rate($before['hard'], $before['reached']), false),
                'chart' => $chart(array_map(static fn (array $s): ?float => self::rate($s['hard'], $s['reached']), $series), 'percent')],
            ['label' => 'Complaint rate', 'value' => $fmtRate(self::rate($now['complained'], $now['reached']), 2), 'note' => $vs,
                'delta' => $pt(self::rate($now['complained'], $now['reached']), self::rate($before['complained'], $before['reached']), false),
                'chart' => $chart(array_map(static fn (array $s): ?float => self::rate($s['complained'], $s['reached']), $series), 'percent')],
        ];
    }

    /** @return array<string, mixed> */
    public static function flow(array $t, OverviewPeriod $p): array
    {
        $rows = [];
        $busiest = null;
        foreach ($t['buckets'] as $key => $counts) {
            $s = self::totals($counts);
            $rows[] = ['label' => $p->bucketLabel($key), 'title' => 'hour' === $p->unit ? $key.' '.$p->zoneAbbreviation() : substr($key, 0, 10)] + $s;
            if ($s['messages'] > 0 && (null === $busiest || $s['messages'] > $rows[$busiest]['messages'])) {
                $busiest = \count($rows) - 1;
            }
        }

        return [
            'rows' => $rows,
            'busiest' => null === $busiest ? null : $rows[$busiest],
            'chart' => [
                'labels' => array_column($rows, 'label'),
                'totals' => array_column($rows, 'messages'),
                'accepted' => array_column($rows, 'accepted'),
                'deferredSoft' => array_column($rows, 'deferred_soft'),
                'bad' => array_map(static fn (array $r): int => $r['hard'] + $r['complained'], $rows),
                'suppressed' => array_column($rows, 'suppressed'),
                'highlight' => $busiest,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function validation(array $v): array
    {
        $c = $v['classes'];
        $groups = [
            ['label' => 'Deliverable or probably deliverable', 'class' => 'ok', 'n' => ($c['deliverable'] ?? 0) + ($c['probably_deliverable'] ?? 0)],
            ['label' => 'Risky, unknown or unverifiable', 'class' => 'mid', 'n' => ($c['risky'] ?? 0) + ($c['unknown'] ?? 0) + ($c['temporarily_unverifiable'] ?? 0)],
            ['label' => 'Undeliverable', 'class' => 'bad', 'n' => $c['undeliverable'] ?? 0],
        ];
        $total = array_sum(array_column($groups, 'n'));
        // Largest-remainder rounding so the 100 squares add up.
        $cells = [];
        if ($total > 0) {
            $exact = array_map(static fn (array $g): float => 100 * $g['n'] / $total, $groups);
            $whole = array_map('intval', array_map('floor', $exact));
            $rest = array_map(static fn (float $e, int $w): float => $e - $w, $exact, $whole);
            arsort($rest);
            foreach (\array_slice(array_keys($rest), 0, 100 - array_sum($whole)) as $i) {
                ++$whole[$i];
            }
            foreach ($groups as $i => $g) {
                $cells = array_merge($cells, array_fill(0, $whole[$i], $g['class']));
            }
        }
        foreach ($groups as $i => $g) {
            $groups[$i]['pct'] = $total > 0 ? number_format(100 * $g['n'] / $total, 1).' %' : '—';
        }
        $squares = [];
        foreach ($cells as $i => $class) {
            $squares[] = ['x' => ($i % 10) * 10 + 1, 'y' => intdiv($i, 10) * 10 + 1, 'class' => $class];
        }

        return ['jobs' => $v['jobs'], 'checked' => $v['checked'], 'classified' => $total, 'groups' => $groups, 'squares' => $squares];
    }
}
