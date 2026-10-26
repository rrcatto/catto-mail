<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Entity\Client;
use App\Entity\User;
use App\Help\HelpCatalog;
use App\System\SettingCatalog;
use App\System\SetupWizard;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The dashboard navigation (owner decision 2026-10-25): a menu bar of areas; an area opens its
 * pages on hover or focus, and a page opens its views (filtered lists, the setup steps, help
 * topics) one tier further. Operators see the operator console's areas, client members (and
 * operators viewing a client) the client workspace's. Every page and view keeps its permission
 * key: it appears only when the user may open it, and an area links to its first page the user
 * may open. Badges count what needs action, only where the user may look. The breadcrumb names
 * the area and the page of the current section.
 */
final class Navigation
{
    /**
     * Operator areas: key => [label, icon, pages]. A page is [section, label, route, permission
     * (null: every operator), views]. A view is [label, route (null: the page's), parameters
     * (`_fragment` for a place on the page), permission (null: the page's)]. The System setup
     * steps and the Help topics are added by build().
     */
    private const OPERATOR = [
        'overview' => ['Overview', 'overview', [
            ['overview', 'Dashboard', 'dashboard_operator_overview', 'PLATFORM.OVERVIEW.VIEW', [
                ['Needs attention', null, ['_fragment' => 'attention'], null],
            ]],
            ['search', 'Search', 'dashboard_search', null, []],
        ]],
        'mail' => ['Mail flow', 'mail', [
            ['system', 'Delivery', 'dashboard_operator_system', 'PLATFORM.SYSTEM.VIEW', [
                ['Delivery mode and emergency stop', null, ['_fragment' => 'delivery-h'], null],
                ['Postfix queue', null, ['_fragment' => 'queue-h'], null],
                ['Host requests', 'dashboard_operator_system_requests', [], null],
            ]],
            ['suppressions', 'Suppressions', 'dashboard_operator_suppressions', 'PLATFORM.SUPPRESSION.VIEW', [
                ['Active', null, ['state' => 'active'], null],
                ['Global (every client)', null, ['state' => 'active', 'scope' => 'global'], null],
                ['Lifted', null, ['state' => 'lifted'], null],
                ['Expired', null, ['state' => 'expired'], null],
            ]],
            ['dsns', 'Unmatched bounces', 'dashboard_operator_dsns', 'PLATFORM.DSN.VIEW', [
                ['Open', null, ['status' => 'open'], null],
                ['Match requested', null, ['status' => 'match_requested'], null],
                ['Matched', null, ['status' => 'matched'], null],
                ['Dismissed', null, ['status' => 'dismissed'], null],
            ]],
            ['webhooks', 'Webhooks', 'dashboard_operator_webhooks', 'PLATFORM.WEBHOOK.VIEW', [
                ['Failed deliveries', null, ['status' => 'failed'], null],
                ['Retrying', null, ['status' => 'retrying'], null],
                ['Delivered', null, ['status' => 'delivered'], null],
                ['Events awaiting fan-out', null, ['state' => 'awaiting_fanout'], null],
            ]],
        ]],
        'clients' => ['Clients', 'clients', [
            ['clients', 'Clients', 'dashboard_operator_clients', 'PLATFORM.CLIENT.VIEW', [
                ['Waiting for approval', null, ['status' => 'pending_approval'], null],
                ['Active', null, ['status' => 'active'], null],
                ['Throttled', null, ['status' => 'throttled'], null],
                ['Suspended', null, ['status' => 'suspended'], null],
                ['Closed', null, ['status' => 'closed'], null],
                ['Public sign-ups', null, ['origin' => 'public_application'], null],
            ]],
            ['alerts', 'Abuse & reputation', 'dashboard_operator_alerts', 'PLATFORM.ABUSE.VIEW', [
                ['Open alerts', null, [], null],
                ['Resolved alerts', null, ['state' => 'resolved'], null],
                ['Every alert', null, ['state' => 'all'], null],
            ]],
            ['usage', 'Usage & billing', 'dashboard_operator_usage', 'PLATFORM.USAGE.VIEW', [
                ['This month', null, ['period' => 'current_month'], null],
                ['Last month', null, ['period' => 'previous_month'], null],
            ]],
            ['batches', 'Address batches', 'dashboard_operator_batches', 'SYSTEM.ADDRESS_BATCH.MANAGE', []],
        ]],
        'system' => ['System', 'system', [
            ['setup', 'System setup', 'dashboard_operator_setup', 'SYSTEM.SETUP.MANAGE', []],
            ['diagnostics', 'Diagnostics', 'dashboard_operator_diagnostics', 'PLATFORM.SYSTEM.VIEW', [
                ['Latest checks', null, [], null],
                ['History', 'dashboard_operator_diagnostics_history', [], null],
            ]],
            ['settings', 'Settings', 'dashboard_operator_settings', 'PLATFORM.SYSTEM.VIEW', []],
            ['audit', 'Audit log', 'dashboard_operator_audit', 'PLATFORM.AUDIT.VIEW', []],
        ]],
        'access' => ['Access', 'access', [
            ['users', 'Users', 'dashboard_operator_users', 'PLATFORM.USER.VIEW', [
                ['Active users', null, ['status' => 'active'], null],
                ['Disabled users', null, ['status' => 'disabled'], null],
            ]],
            ['roles', 'Roles & permissions', 'dashboard_operator_roles', 'SYSTEM.ROLE.VIEW', []],
        ]],
    ];

    /** Client workspace areas: key => [label, icon, pages]; a page is [section, label, route, views], a view [label, parameters]. */
    private const CLIENT = [
        'overview' => ['Overview', 'overview', [['overview', 'Overview', 'dashboard_client_overview', []]]],
        'sending' => ['Sending', 'send', [
            ['sending', 'Send jobs', 'dashboard_client_send_jobs', [
                ['In progress', ['status' => 'processing']],
                ['Completed', ['status' => 'completed']],
                ['Failed', ['status' => 'failed']],
            ]],
            ['suppressions', 'Suppressions', 'dashboard_client_suppressions', []],
        ]],
        'validation' => ['Validation', 'check', [['validation', 'Validation jobs', 'dashboard_client_validation_jobs', [
            ['In progress', ['status' => 'processing']],
            ['Completed', ['status' => 'completed']],
            ['Failed', ['status' => 'failed']],
        ]]]],
        'settings' => ['Settings', 'settings', [
            ['domains', 'Sending domains', 'dashboard_client_domains', []],
            ['api_keys', 'API keys', 'dashboard_client_api_keys', []],
            ['webhooks', 'Webhooks', 'dashboard_client_webhooks', [
                ['Failed deliveries', ['status' => 'failed']],
                ['Retrying', ['status' => 'retrying']],
            ]],
            ['connections', 'Connected applications', 'dashboard_client_connections', []],
        ]],
        'usage' => ['Usage', 'usage', [['usage', 'Usage & limits', 'dashboard_client_usage', []]]],
    ];

    /** @var array<string, int>|null */
    private ?array $counts = null;

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
        private readonly OperatorReadModel $read,
        private readonly SetupWizard $wizard,
        private readonly SettingCatalog $settings,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(SMARTHOST_ENV)%')] private readonly string $environment = 'development',
    ) {
    }

    /**
     * @return array{context: ?string, menus: list<array<string, mixed>>, crumbs: list<array{label: string, href: ?string}>,
     *               switch: ?array{href: string, label: string}, attention: int}
     */
    public function build(?Client $client, string $section): array
    {
        $user = $this->security->getUser();
        $nav = ['context' => null, 'menus' => [], 'crumbs' => [], 'switch' => null, 'attention' => 0];
        if (!$user instanceof User) {
            return $nav;
        }
        $platform = $user->isPlatformUser();
        $badges = $platform ? $this->badges() : [];
        $nav['attention'] = $platform ? array_sum(array_map(static fn (array $b): int => $b[0], array_filter($badges, static fn (array $b, string $k): bool => !str_contains($k, '.'), \ARRAY_FILTER_USE_BOTH))) : 0;

        if (null !== $client) {
            $nav['context'] = 'client';
            $params = ['clientId' => $client->getId()->toRfc4122()];
            foreach (self::CLIENT as $key => [$label, $icon, $pages]) {
                $items = [];
                foreach ($pages as [$s, $l, $route, $views]) {
                    $href = $this->urls->generate($route, $params);
                    $items[] = $this->item($s, $l, $href, $section, null, null, array_map(
                        fn (array $v): array => $this->view($v[0], $this->urls->generate($route, $params + $v[1])), $views));
                }
                $this->addMenu($nav, $key, $label, $icon, $items, null);
            }
            $nav['switch'] = $platform
                ? ['href' => $this->urls->generate('dashboard_operator_overview'), 'label' => 'Back to the operator console']
                : ['href' => $this->urls->generate('dashboard_home'), 'label' => 'Switch client'];

            return $nav;
        }

        if (!$platform) {
            return $nav;
        }
        $nav['context'] = 'operator';
        foreach (self::OPERATOR as $key => [$label, $icon, $pages]) {
            $items = [];
            foreach ($pages as [$s, $l, $route, $permission, $views]) {
                if (null !== $permission && !$this->security->isGranted($permission)) {
                    continue;
                }
                $built = [];
                foreach ($views as [$vl, $vroute, $vparams, $vpermission]) {
                    if (null === $vpermission || $this->security->isGranted($vpermission)) {
                        $built[] = $this->view($vl, $this->urls->generate($vroute ?? $route, $vparams));
                    }
                }
                $note = null;
                if ('setup' === $s) {
                    [$built, $note] = $this->setupViews();
                } elseif ('settings' === $s) {
                    foreach (array_keys($this->settings->grouped($this->environment)) as $group) {
                        $built[] = $this->view($group, $this->urls->generate($route, ['group' => SettingCatalog::slug($group)]));
                    }
                } elseif ('audit' === $s) {
                    foreach (OperatorReadModel::AUDIT_VIEWS as $view => [$vl]) {
                        $built[] = $this->view($vl, $this->urls->generate($route, '' === $view ? [] : ['view' => $view]));
                    }
                }
                $items[] = $this->item($s, $l, $this->urls->generate($route), $section, $badges["$key.$s"] ?? null, $note, $built);
            }
            $this->addMenu($nav, $key, $label, $icon, $items, $badges[$key] ?? null);
        }
        if ($this->security->isGranted('PLATFORM.HELP.VIEW')) {
            $items = [$this->item('help', 'All topics', $this->urls->generate('dashboard_operator_help'), $section, null, null, [])];
            foreach (HelpCatalog::GROUPS as $group => $topics) {
                $views = [];
                foreach ($topics as $slug => $title) {
                    $views[] = $this->view($title, $this->urls->generate('dashboard_operator_help_topic', ['topic' => $slug]));
                }
                $items[] = $this->item('help:'.$group, $group, $this->urls->generate('dashboard_operator_help', ['_fragment' => self::anchor($group)]), $section, null, null, $views);
            }
            $this->addMenu($nav, 'help', 'Help', 'help', $items, null);
        }

        return $nav;
    }

    /** The id of a help group's heading on the help index. */
    public static function anchor(string $group): string
    {
        return 'g-'.trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($group)), '-');
    }

    /**
     * @param list<array<string, mixed>>      $items
     * @param array{0: int, 1: string}|null $badge
     * @param array<string, mixed>          $nav
     */
    private function addMenu(array &$nav, string $key, string $label, string $icon, array $items, ?array $badge): void
    {
        if ([] === $items) {
            return;
        }
        $current = null;
        foreach ($items as $i) {
            if ($i['current']) {
                $current = $i;
            }
        }
        $nav['menus'][] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'href' => $items[0]['href'],
            'current' => null !== $current, 'badge' => $badge, 'items' => $items];
        if (null !== $current) {
            $nav['crumbs'] = $current['label'] === $label ? [['label' => $label, 'href' => $current['href']]]
                : [['label' => $label, 'href' => null], ['label' => $current['label'], 'href' => $current['href']]];
        }
    }

    /**
     * @param array{0: int, 1: string}|null $badge
     * @param list<array<string, mixed>>      $views
     *
     * @return array<string, mixed>
     */
    private function item(string $s, string $label, string $href, string $section, ?array $badge, ?string $note, array $views): array
    {
        return ['label' => $label, 'href' => $href, 'current' => $s === $section, 'badge' => $badge, 'note' => $note, 'views' => $views];
    }

    /** @return array<string, mixed> */
    private function view(string $label, ?string $href, ?string $state = null, ?string $note = null): array
    {
        return ['label' => $label, 'href' => $href, 'header' => null === $href, 'state' => $state, 'note' => $note];
    }

    /**
     * The setup steps as views (a group heading, then each step with its state) and the
     * "n of 19" note while setup is not complete.
     *
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    private function setupViews(): array
    {
        $steps = $this->wizard->steps();
        $views = [$this->view('All steps', $this->urls->generate('dashboard_operator_setup'))];
        foreach (SetupWizard::GROUPS as $group => $keys) {
            if (['welcome'] === $keys) {
                continue;
            }
            $views[] = $this->view($group, null);
            foreach ($keys as $k) {
                $state = $steps[$k]['state'] ?? null;
                $views[] = $this->view($steps[$k]['title'], $this->urls->generate('dashboard_operator_setup_step', ['step' => $k]),
                    $state ?? 'todo', match ($state) { 'done' => 'done', 'skipped' => 'skipped', default => 'to do' });
            }
        }
        $done = \count(array_filter($steps, static fn (array $s): bool => 'done' === $s['state']));

        return [$views, $done < \count($steps) ? $done.' of '.\count($steps) : null];
    }

    /**
     * Badges as [count, tone] by area key, and by "area.section" for pages; only counts the
     * user may look at, and only non-zero ones.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    private function badges(): array
    {
        $c = $this->counts ??= $this->read->navCounts();
        $can = fn (string $permission): bool => $this->security->isGranted($permission);
        $alerts = $can('PLATFORM.ABUSE.VIEW') ? $c['open_alerts'] : 0;
        $pending = $can('PLATFORM.CLIENT.VIEW') ? $c['pending_clients'] + $c['new_sign_ups'] : 0;   // and public sign-ups of the last 7 days
        $dsns = $can('PLATFORM.DSN.VIEW') ? $c['open_dsns'] : 0;
        $webhooks = $can('PLATFORM.WEBHOOK.VIEW') ? $c['failed_webhooks'] : 0;
        $checks = $can('PLATFORM.SYSTEM.VIEW') ? $c['warn_checks'] + $c['fail_checks'] : 0;
        $checkTone = $c['fail_checks'] > 0 ? 'bad' : 'warn';

        return array_filter([
            'clients' => [$alerts + $pending, $alerts > 0 ? 'bad' : 'info'],
            'clients.clients' => [$pending, 'info'],
            'clients.alerts' => [$alerts, 'bad'],
            'mail' => [$dsns + $webhooks, $webhooks > 0 ? 'bad' : 'info'],
            'mail.dsns' => [$dsns, 'info'],
            'mail.webhooks' => [$webhooks, 'bad'],
            'system' => [$checks, $checkTone],
            'system.diagnostics' => [$checks, $checkTone],
        ], static fn (array $b): bool => $b[0] > 0);
    }
}
