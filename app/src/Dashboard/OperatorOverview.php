<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Controller\Dashboard\OperatorSystemController;
use App\Enum\ClientAlertMetric;
use App\Reputation\ReputationThresholds;
use App\System\SetupWizard;
use App\System\SystemChecks;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Everything the operator overview page shows, from durable database signals only (no
 * shell command, no network probe): KPI cards with the previous-period comparison, the
 * chart series (drawn by assets/controllers/chart_controller.js), workers and queues,
 * what needs attention, the per-client rates, validation results, component health,
 * suppressions and recent activity. Items link only to pages the user may open.
 */
final class OperatorOverview
{
    /** Scatter colours by client volume rank; the rest share the last one. */
    private const CLIENT_COLOURS = [['#053D1F', '#053D1F'], ['#0E8A44', '#0B6B35'], ['#22D56B', '#12A150'], ['#B8E8CB', '#5FB383']];
    private const OTHER_COLOUR = ['#D5DDD8', '#9AA8A0'];

    public function __construct(
        private readonly OperatorReadModel $read,
        private readonly SystemChecks $checks,
        private readonly SetupWizard $wizard,
        // The delivery status the page layout also shows, read once per request.
        private readonly DashboardTwigExtension $twig,
        private readonly ReputationThresholds $thresholds,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(OverviewPeriod $period): array
    {
        $o = $this->read->overviewSnapshot();
        $t = $this->read->trends($period);
        $hard = $this->thresholds->of(ClientAlertMetric::HardBounceRate);
        $complaint = $this->thresholds->of(ClientAlertMetric::ComplaintRate);
        $health = $this->health();

        return [
            'period' => $period,
            'periods' => array_map(static fn (array $p): string => $p[0], OverviewPeriod::PERIODS),
            'o' => $o,
            'kpis' => TrendCharts::kpis($t, $period),
            'flow' => TrendCharts::flow($t, $period),
            'rate' => $this->submissionChart($period),
            'scatter' => $this->scatter($t['jobs'], $period, $hard),
            'validation' => TrendCharts::validation($t['validation']),
            'rates' => $this->rates($t['rates'], $hard, $complaint),
            'thresholds' => ['hard' => $hard, 'complaint' => $complaint],
            'health' => $health,
            'attention' => $this->attention($o, $health),
            'activity' => $this->security->isGranted('PLATFORM.AUDIT.VIEW') ? $this->activity() : null,
        ];
    }





    /**
     * Messages submitted to Postfix per minute in each bucket of the period (typical and peak),
     * against the installation-wide rate ceiling (DELIVERY_GLOBAL_RATE_PER_MINUTE, read from the
     * delivery daemon's heartbeat). Also used by Mail flow › Delivery.
     *
     * @return array<string, mixed>
     */
    public function submissionChart(OverviewPeriod $p): array
    {
        $rates = $this->read->submissionRate($p);
        $ceiling = (int) ($this->twig->deliveryStatus()['global_rate_per_minute'] ?? 0);
        $labels = $typical = $peak = $titles = [];
        $busiest = null;
        foreach ($p->bucketKeys() as $i => $key) {
            $r = $rates[$key] ?? null;
            $labels[] = $p->bucketLabel($key);
            $titles[] = 'hour' === $p->unit ? $key.' '.$p->zoneAbbreviation() : substr($key, 0, 10);
            $typical[] = $r['typical'] ?? null;
            $peak[] = $r['peak'] ?? null;
            if (null !== $r && (null === $busiest || $r['peak'] > $peak[$busiest])) {
                $busiest = $i;
            }
        }

        return [
            'busiest' => null === $busiest ? null : ['title' => $titles[$busiest], 'peak' => $peak[$busiest], 'typical' => $typical[$busiest]],
            'ceiling' => $ceiling > 0 ? $ceiling : null,
            'chart' => ['labels' => $labels, 'titles' => $titles, 'typical' => $typical, 'peak' => $peak, 'highlight' => $busiest,
                'ceiling' => $ceiling > 0 ? $ceiling : null],
        ];
    }

    /**
     * Send jobs of the period by hard-bounce rate; the four clients with the most mail get
     * their own colour, the others share one.
     *
     * @param array{warning: float, critical: float} $hard
     *
     * @return array<string, mixed>
     */
    private function scatter(array $jobs, OverviewPeriod $p, array $hard): array
    {
        $volume = [];
        foreach ($jobs as $j) {
            $volume[$j['client_id']] = ['name' => $j['company_name'], 'n' => ($volume[$j['client_id']]['n'] ?? 0) + (int) $j['reached_postfix']];
        }
        uasort($volume, static fn (array $a, array $b): int => $b['n'] <=> $a['n']);
        $series = [];
        $map = [];
        $rank = 0;
        foreach ($volume as $clientId => $v) {
            $own = $rank < \count(self::CLIENT_COLOURS);
            $key = $own ? (string) $clientId : 'other';
            [$colour, $edge] = $own ? self::CLIENT_COLOURS[$rank] : self::OTHER_COLOUR;
            $series[$key] ??= ['label' => $own ? $v['name'] : 'Other clients', 'colour' => $colour, 'edge' => $edge, 'points' => []];
            $map[$clientId] = $key;
            ++$rank;
        }
        $above = 0;
        foreach ($jobs as $j) {
            $rate = 100.0 * (int) $j['hard_bounced'] / (int) $j['reached_postfix'];
            $above += $rate >= $hard['warning'] ? 1 : 0;
            $series[$map[$j['client_id']]]['points'][] = ['x' => (new \DateTimeImmutable((string) $j['created_at']))->getTimestamp() * 1000,
                'y' => round($rate, 2), 'ref' => (string) $j['external_reference'], 'n' => (int) $j['reached_postfix'], 'client' => $j['company_name']];
        }
        $end = $p->since->modify(\sprintf('+%d %s', $p->buckets, $p->unit));

        return [
            'legend' => array_map(static fn (array $s): array => ['label' => $s['label'], 'colour' => $s['colour'], 'edge' => $s['edge']], array_values($series)),
            'jobs' => \count($jobs),
            'above' => $above,
            'chart' => ['series' => array_values($series), 'min' => $p->since->getTimestamp() * 1000, 'max' => $end->getTimestamp() * 1000,
                'warning' => $hard['warning'], 'critical' => $hard['critical'], 'hourly' => 'hour' === $p->unit, 'timeZone' => $p->zone->getName()],
        ];
    }


    /**
     * @param array{warning: float, critical: float} $hard
     * @param array{warning: float, critical: float} $complaint
     *
     * @return list<array<string, mixed>>
     */
    private function rates(array $rows, array $hard, array $complaint): array
    {
        $meter = static function (mixed $rate, array $t): ?array {
            if (null === $rate) {
                return null;
            }
            $r = (float) $rate;

            return ['width' => round(min(1.0, $r / $t['critical']) * 100, 1), 'tick' => round($t['warning'] / $t['critical'] * 100, 1),
                'tone' => $r >= $t['critical'] ? 'bad' : ($r >= $t['warning'] ? 'warn' : 'ok')];
        };

        return array_map(static fn (array $r): array => $r + [
            'accepted_rate' => (int) $r['reached_postfix'] > 0 ? number_format(100 * (int) $r['remote_accepted'] / (int) $r['reached_postfix'], 1).' %' : '—',
            'hard_meter' => $meter($r['hard_bounce_rate'], $hard),
            'complaint_meter' => $meter($r['complaint_rate'], $complaint),
        ], $rows);
    }

    /** @return array{rows: list<array{label: string, result: string}>, pass: int, total: int, last: ?string}|null */
    private function health(): ?array
    {
        if (!$this->security->isGranted('PLATFORM.SYSTEM.VIEW')) {
            return null;
        }
        $all = $this->checks->latest();
        $rows = [];
        foreach (OperatorSystemController::HEALTH_ROWS as $label => [$components, $prefixes]) {
            $rows[] = ['label' => $label, 'result' => SetupWizard::worst(SetupWizard::select($all, $components, $prefixes))];
        }
        $last = [] === $all ? null : max(array_map(static fn (array $c): string => (string) $c['ran_at'], $all));

        return ['rows' => $rows, 'pass' => \count(array_filter($rows, static fn (array $r): bool => 'pass' === $r['result'])), 'total' => \count($rows), 'last' => $last];
    }

    /**
     * What needs an operator's attention, worst first. Each item links to the page where it
     * is handled, and appears only when the user may open that page.
     *
     * @return list<array{tone: string, icon: string, title: string, meta: string, href: ?string}>
     */
    private function attention(array $o, ?array $health): array
    {
        $can = fn (string $p): bool => $this->security->isGranted($p);
        $url = fn (string $route, array $params = []): string => $this->urls->generate($route, $params);
        $system = $can('PLATFORM.SYSTEM.VIEW') ? $url('dashboard_operator_system') : null;
        $items = [];
        $add = static function (string $tone, string $icon, string $title, string $meta, ?string $href) use (&$items): void {
            $items[] = ['tone' => $tone, 'icon' => $icon, 'title' => $title, 'meta' => $meta, 'href' => $href];
        };

        // Stalled work (the durable signals of a stopped worker).
        $v = $o['validation'];
        if ($v['pending'] > 0 && 0 === $v['active_workers']) {
            $add('bad', 'alert', 'Addresses are waiting and no validation worker holds a lease', number_format($v['pending']).' addresses waiting', $system);
        }
        $s = $o['sending'];
        if ($s['queued_jobs'] + $s['processing_jobs'] > 0 && 0 === $s['active_workers']) {
            $add('bad', 'alert', 'Send work is waiting and no delivery worker holds a lease', ($s['queued_jobs'] + $s['processing_jobs']).' send jobs waiting', $system);
        }
        if ($o['webhooks']['events_awaiting_fanout'] > 0 && 0 === $o['webhooks']['workers']) {
            $add('bad', 'alert', 'Webhook events are waiting and no webhook worker is running', number_format($o['webhooks']['events_awaiting_fanout']).' events waiting',
                $can('PLATFORM.WEBHOOK.VIEW') ? $url('dashboard_operator_webhooks') : null);
        }
        $d = $this->twig->deliveryStatus();
        if ('STOPPED' !== $d['mode'] && (null === $d['queue_snapshot_age'] || $d['queue_snapshot_age'] > 300)) {
            $add('warn', 'alert', 'No fresh Postfix queue snapshot', 'The snapshot timer or Postfix may be stopped; reconciliation draws no conclusions meanwhile', $system);
        }

        if ($can('PLATFORM.ABUSE.VIEW') && ($o['alerts']['critical'] ?? 0) + ($o['alerts']['warning'] ?? 0) > 0) {
            foreach ($this->read->openAlerts(3) as $a) {
                $factor = 'volume_increase' === $a['metric'];
                $num = static fn (mixed $x): string => ($factor ? '×' : '').rtrim(rtrim(number_format((float) $x, 2), '0'), '.').($factor ? '' : ' %');
                $add('critical' === $a['severity'] ? 'bad' : 'warn', 'alert',
                    \sprintf('%s: %s %s', $a['company_name'], mb_strtolower(Labels::label((string) $a['metric'], 'alert_metric')), $num($a['value'])),
                    \sprintf('%s reputation alert · threshold %s', Labels::label((string) $a['severity'], 'alert_severity'), $num($a['threshold'])),
                    $url('dashboard_operator_alerts', ['client' => $a['client_id']]));
            }
        }
        if ($can('PLATFORM.WEBHOOK.VIEW') && ($o['webhooks']['deliveries']['failed'] ?? 0) > 0) {
            $n = $o['webhooks']['deliveries']['failed'];
            $add('bad', 'link', \sprintf('%d webhook deliver%s failed', $n, 1 === $n ? 'y' : 'ies'), 'Last 24 hours', $url('dashboard_operator_webhooks', ['status' => 'failed']));
        }
        if (null !== $health) {
            foreach ($health['rows'] as $r) {
                if (\in_array($r['result'], ['warn', 'fail'], true)) {
                    $add('fail' === $r['result'] ? 'bad' : 'warn', 'activity', $r['label'].' check: '.('fail' === $r['result'] ? 'failing' : 'warning'),
                        'Latest diagnostics', $url('dashboard_operator_diagnostics'));
                }
            }
        }
        if ($can('PLATFORM.DSN.VIEW') && ($o['unmatched_dsns']['open'] ?? 0) > 0) {
            $n = $o['unmatched_dsns']['open'];
            $add('info', 'inbox', \sprintf('%d unmatched DSN%s to review', $n, 1 === $n ? '' : 's'), 'Bounces not tied to a message', $url('dashboard_operator_dsns'));
        }
        if ($can('PLATFORM.CLIENT.VIEW') && ($o['clients']['pending_approval'] ?? 0) > 0) {
            $n = $o['clients']['pending_approval'];
            $add('info', 'user', 1 === $n ? $o['first_pending_client'].' awaits approval' : \sprintf('%d clients await approval', $n), 'Approval queue',
                $url('dashboard_operator_clients', ['status' => 'pending_approval', 'sort' => 'status_changed', 'dir' => 'asc']));
        }
        if ($can('SYSTEM.SETUP.MANAGE') && !$this->wizard->isComplete()) {
            $add('info', 'list', 'The system setup is not complete', 'Continue the setup wizard', $url('dashboard_operator_setup'));
        }

        $order = ['bad' => 0, 'warn' => 1, 'info' => 2];
        usort($items, static fn (array $a, array $b): int => $order[$a['tone']] <=> $order[$b['tone']]);

        return $items;
    }

    /** @return list<array{who: string, actor: string, action: string, target: string, at: mixed}> */
    private function activity(): array
    {
        return array_map(static function (array $a): array {
            $actor = match ($a['actor_type']) {
                'user' => (string) ($a['actor_email'] ?? 'A user'),
                'api_key' => 'An API key',
                default => 'System',
            };

            return ['who' => 'user' === $a['actor_type'] ? DashboardTwigExtension::initials(strtok($actor, '@') ?: $actor) : ('api_key' === $a['actor_type'] ? 'API' : 'SY'),
                'actor' => $actor, 'action' => ucfirst(str_replace(['.', '_'], [': ', ' '], (string) $a['action'])),
                'target' => str_replace('_', ' ', (string) $a['target_type']), 'at' => $a['occurred_at']];
        }, $this->read->recentActivity(5));
    }
}
