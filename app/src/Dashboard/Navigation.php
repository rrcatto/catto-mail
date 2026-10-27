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
     * Operator areas: key => [label, icon, pages]. A page is [section, label, icon, route, permission
     * (null: every operator), views]. A view is [label, route (null: the page's), parameters
     * (`_fragment` for a place on the page), permission (null: the page's), icon]. The System setup
     * steps and the Help topics are added by build(). Every area, page and view has its Bootstrap
     * Icons icon (owner decision 2026-10-27; dashboard/_icons.html.twig).
     */
    private const OPERATOR = [
        'overview' => ['Overview', 'overview', [
            ['overview', 'Dashboard', 'speedometer2', 'dashboard_operator_overview', 'PLATFORM.OVERVIEW.VIEW', [
                ['Needs attention', null, ['_fragment' => 'attention'], null, 'exclamation-circle'],
            ]],
            ['search', 'Search', 'search', 'dashboard_search', null, []],
        ]],
        'mail' => ['Mail flow', 'mail', [
            ['system', 'Delivery', 'truck', 'dashboard_operator_system', 'PLATFORM.SYSTEM.VIEW', [
                ['Delivery mode and emergency stop', null, ['_fragment' => 'delivery-h'], null, 'toggles'],
                ['Postfix queue', null, ['_fragment' => 'queue-h'], null, 'stack'],
                ['Host requests', 'dashboard_operator_system_requests', [], null, 'hdd-network'],
            ]],
            ['suppressions', 'Suppressions', 'slash-circle', 'dashboard_operator_suppressions', 'PLATFORM.SUPPRESSION.VIEW', [
                ['Active', null, ['state' => 'active'], null, 'check-circle'],
                ['Global (every client)', null, ['state' => 'active', 'scope' => 'global'], null, 'globe2'],
                ['Lifted', null, ['state' => 'lifted'], null, 'arrow-counterclockwise'],
                ['Expired', null, ['state' => 'expired'], null, 'clock-history'],
            ]],
            ['dsns', 'Unmatched bounces', 'envelope-exclamation', 'dashboard_operator_dsns', 'PLATFORM.DSN.VIEW', [
                ['Open', null, ['status' => 'open'], null, 'envelope-open'],
                ['Match requested', null, ['status' => 'match_requested'], null, 'hourglass-split'],
                ['Matched', null, ['status' => 'matched'], null, 'link-45deg'],
                ['Dismissed', null, ['status' => 'dismissed'], null, 'x-circle'],
            ]],
            ['webhooks', 'Webhooks', 'broadcast', 'dashboard_operator_webhooks', 'PLATFORM.WEBHOOK.VIEW', [
                ['Failed deliveries', null, ['status' => 'failed'], null, 'x-octagon'],
                ['Retrying', null, ['status' => 'retrying'], null, 'arrow-repeat'],
                ['Delivered', null, ['status' => 'delivered'], null, 'check2-all'],
                ['Events not yet sent out', null, ['state' => 'awaiting_fanout'], null, 'hourglass'],
            ]],
        ]],
        'clients' => ['Clients', 'clients', [
            ['clients', 'Clients', 'building', 'dashboard_operator_clients', 'PLATFORM.CLIENT.VIEW', [
                ['Waiting for approval', 'dashboard_operator_clients_view', ['view' => 'waiting'], null, 'hourglass-split'],
                ['Active', 'dashboard_operator_clients_view', ['view' => 'active'], null, 'check-circle'],
                ['Throttled', 'dashboard_operator_clients_view', ['view' => 'throttled'], null, 'speedometer'],
                ['Suspended', 'dashboard_operator_clients_view', ['view' => 'suspended'], null, 'pause-circle'],
                ['Closed', 'dashboard_operator_clients_view', ['view' => 'closed'], null, 'x-circle'],
                ['Opened by invitation', 'dashboard_operator_clients_view', ['view' => 'invited'], null, 'envelope-paper'],
                ['Public sign-ups', 'dashboard_operator_clients_view', ['view' => 'sign-ups'], null, 'person-plus'],
            ]],
            ['client_new', 'New client', 'building-add', 'dashboard_operator_client_new', 'PLATFORM.CLIENT.APPROVE', []],
            ['invitations', 'Invitations', 'envelope-paper', 'dashboard_operator_invitations', 'PLATFORM.CLIENT.APPROVE', [
                ['Invite someone', 'dashboard_operator_invitation_new', [], null, 'envelope-plus'],
            ]],
            ['plans', 'Plans', 'layers', 'dashboard_operator_plans', 'PLATFORM.CLIENT.VIEW', [
                ['New plan', 'dashboard_operator_plan_new', [], 'PLATFORM.CLIENT_LIMIT.MANAGE', 'plus-lg'],
            ]],
            ['alerts', 'Abuse & reputation', 'shield-exclamation', 'dashboard_operator_alerts', 'PLATFORM.ABUSE.VIEW', [
                ['Open alerts', null, [], null, 'exclamation-triangle'],
                ['Resolved alerts', null, ['state' => 'resolved'], null, 'check-circle'],
                ['Every alert', null, ['state' => 'all'], null, 'list-ul'],
            ]],
            ['usage', 'Usage & billing', 'receipt', 'dashboard_operator_usage', 'PLATFORM.USAGE.VIEW', [
                ['This month', null, ['period' => 'current_month'], null, 'calendar-day'],
                ['Last month', null, ['period' => 'previous_month'], null, 'calendar-minus'],
            ]],
            ['batches', 'Address batches', 'collection', 'dashboard_operator_batches', 'SYSTEM.ADDRESS_BATCH.MANAGE', []],
        ]],
        'system' => ['System', 'system', [
            ['setup', 'System setup', 'list-check', 'dashboard_operator_setup', 'SYSTEM.SETUP.MANAGE', []],
            ['diagnostics', 'Diagnostics', 'heart-pulse', 'dashboard_operator_diagnostics', 'PLATFORM.SYSTEM.VIEW', [
                ['Latest checks', null, [], null, 'clipboard-check'],
                ['History', 'dashboard_operator_diagnostics_history', [], null, 'clock-history'],
            ]],
            ['settings', 'Settings', 'gear', 'dashboard_operator_settings', 'PLATFORM.SYSTEM.VIEW', []],
            ['audit', 'Audit log', 'journal-text', 'dashboard_operator_audit', 'PLATFORM.AUDIT.VIEW', []],
        ]],
        'access' => ['Access', 'access', [
            ['users', 'Users', 'person-badge', 'dashboard_operator_users', 'PLATFORM.USER.VIEW', [
                ['Active users', 'dashboard_operator_users_view', ['status' => 'active'], null, 'person-check'],
                ['Disabled users', 'dashboard_operator_users_view', ['status' => 'disabled'], null, 'person-slash'],
            ]],
            ['roles', 'Roles & permissions', 'key', 'dashboard_operator_roles', 'SYSTEM.ROLE.VIEW', []],
        ]],
    ];

    /** Client workspace areas: key => [label, icon, pages]; a page is [section, label, icon, route, views], a view [label, parameters, icon]. */
    private const CLIENT = [
        'overview' => ['Overview', 'overview', [['overview', 'Overview', 'speedometer2', 'dashboard_client_overview', []]]],
        'sending' => ['Sending', 'send', [
            ['sending', 'Send jobs', 'send', 'dashboard_client_send_jobs', [
                ['In progress', ['status' => 'processing'], 'hourglass-split'],
                ['Completed', ['status' => 'completed'], 'check-circle'],
                ['Failed', ['status' => 'failed'], 'x-circle'],
            ]],
            ['suppressions', 'Suppressions', 'slash-circle', 'dashboard_client_suppressions', []],
        ]],
        'validation' => ['Validation', 'check', [['validation', 'Validation jobs', 'list-check', 'dashboard_client_validation_jobs', [
            ['In progress', ['status' => 'processing'], 'hourglass-split'],
            ['Completed', ['status' => 'completed'], 'check-circle'],
            ['Failed', ['status' => 'failed'], 'x-circle'],
        ]]]],
        'settings' => ['Settings', 'settings', [
            ['domains', 'Sending domains', 'globe2', 'dashboard_client_domains', []],
            ['api_keys', 'API keys', 'key', 'dashboard_client_api_keys', []],
            ['webhooks', 'Webhooks', 'broadcast', 'dashboard_client_webhooks', [
                ['Failed deliveries', ['status' => 'failed'], 'x-octagon'],
                ['Retrying', ['status' => 'retrying'], 'arrow-repeat'],
            ]],
            ['connections', 'Connected applications', 'plug', 'dashboard_client_connections', []],
        ]],
        'usage' => ['Usage', 'usage', [['usage', 'Usage & limits', 'bar-chart-line', 'dashboard_client_usage', []]]],
    ];

    /** Icons of the setting groups (System › Settings) and the audit log's views; others get the page's. */
    private const SETTING_GROUP_ICONS = [
        'Installation' => 'house-gear', 'Mail server and domains' => 'hdd-network', 'Dashboard sign-in' => 'box-arrow-in-right',
        'Delivery pacing' => 'speedometer', 'Bounces and reconciliation' => 'arrow-return-left', 'Email validation' => 'patch-check',
        'API and client limits' => 'sliders2', 'Webhooks' => 'broadcast', 'Reputation alerts' => 'shield-exclamation',
        'Data retention' => 'archive', "Certificates (Let's Encrypt)" => 'lock', 'Backups' => 'database-down',
    ];
    private const AUDIT_VIEW_ICONS = [
        '' => 'list-ul', 'sign-in-emails' => 'envelope-at', 'sign-ins' => 'box-arrow-in-right', 'sign-up-emails' => 'envelope-plus',
        'server-links' => 'terminal', 'delivery' => 'truck', 'setup' => 'gear', 'clients' => 'building', 'lists' => 'collection',
        'suppressions' => 'slash-circle', 'access' => 'people',
    ];
    /** The Help groups' icons, as on the Help index. */
    public const HELP_GROUP_ICONS = [
        'Start here' => 'overview', 'Validation and lists' => 'check', 'Sending and results' => 'send', 'Mail identity (DNS)' => 'mail',
        'The components in depth' => 'system', 'Clients and integration' => 'clients', 'Operations' => 'settings',
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
                foreach ($pages as [$s, $l, $pageIcon, $route, $views]) {
                    $href = $this->urls->generate($route, $params);
                    $items[] = $this->item($s, $l, $pageIcon, $href, $section, null, null, array_map(
                        fn (array $v): array => $this->view($v[0], $this->urls->generate($route, $params + $v[1]), icon: $v[2]), $views));
                }
                $this->addMenu($nav, $key, $label, $icon, $items, null);
            }
            $nav['switch'] = $platform
                ? ['href' => $this->urls->generate('dashboard_operator_overview'), 'label' => 'Back to the operator console']
                : ['href' => $this->urls->generate('dashboard_home'), 'label' => 'Switch account'];

            return $nav;
        }

        if (!$platform) {
            return $nav;
        }
        $nav['context'] = 'operator';
        foreach (self::OPERATOR as $key => [$label, $icon, $pages]) {
            $items = [];
            foreach ($pages as [$s, $l, $pageIcon, $route, $permission, $views]) {
                if (null !== $permission && !$this->security->isGranted($permission)) {
                    continue;
                }
                $built = [];
                foreach ($views as [$vl, $vroute, $vparams, $vpermission, $vicon]) {
                    if (null === $vpermission || $this->security->isGranted($vpermission)) {
                        $built[] = $this->view($vl, $this->urls->generate($vroute ?? $route, $vparams), icon: $vicon);
                    }
                }
                $note = null;
                if ('setup' === $s) {
                    [$built, $note] = $this->setupViews();
                } elseif ('settings' === $s) {
                    foreach (array_keys($this->settings->grouped($this->environment)) as $group) {
                        $built[] = $this->view($group, $this->urls->generate($route, ['group' => SettingCatalog::slug($group)]), icon: self::SETTING_GROUP_ICONS[$group] ?? $pageIcon);
                    }
                } elseif ('audit' === $s) {
                    foreach (OperatorReadModel::AUDIT_VIEWS as $view => [$vl]) {
                        $built[] = $this->view($vl, $this->urls->generate($route, '' === $view ? [] : ['view' => $view]), icon: self::AUDIT_VIEW_ICONS[$view] ?? $pageIcon);
                    }
                }
                $items[] = $this->item($s, $l, $pageIcon, $this->urls->generate($route), $section, $badges["$key.$s"] ?? null, $note, $built);
            }
            $this->addMenu($nav, $key, $label, $icon, $items, $badges[$key] ?? null);
        }
        if ($this->security->isGranted('PLATFORM.HELP.VIEW')) {
            $items = [$this->item('help', 'All topics', 'book', $this->urls->generate('dashboard_operator_help'), $section, null, null, [])];
            foreach (HelpCatalog::GROUPS as $group => $topics) {
                $views = [];
                foreach ($topics as $slug => $title) {
                    $views[] = $this->view($title, $this->urls->generate('dashboard_operator_help_topic', ['topic' => $slug]), icon: 'file-earmark-text');
                }
                $items[] = $this->item('help:'.$group, $group, self::HELP_GROUP_ICONS[$group] ?? 'help', $this->urls->generate('dashboard_operator_help', ['_fragment' => self::anchor($group)]), $section, null, null, $views);
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
    private function item(string $s, string $label, string $icon, string $href, string $section, ?array $badge, ?string $note, array $views): array
    {
        return ['label' => $label, 'icon' => $icon, 'href' => $href, 'current' => $s === $section, 'badge' => $badge, 'note' => $note, 'views' => $views];
    }

    /** @return array<string, mixed> */
    private function view(string $label, ?string $href, ?string $state = null, ?string $note = null, ?string $icon = null): array
    {
        return ['label' => $label, 'href' => $href, 'header' => null === $href, 'state' => $state, 'note' => $note, 'icon' => $icon];
    }

    /**
     * The setup steps that need the administrator as views, each with its state, then the steps
     * checked automatically as one link; the "n of 6" note while those are not all done.
     *
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    private function setupViews(): array
    {
        $steps = $this->wizard->steps();
        $views = [$this->view('All steps', $this->urls->generate('dashboard_operator_setup'), icon: 'list-check'), $this->view('Needs you', null)];
        foreach (SetupWizard::NEEDS_YOU as $k) {
            $state = $steps[$k]['state'] ?? null;
            // A step's icon is its state: done, skipped or still to do.
            $views[] = $this->view($steps[$k]['title'], $this->urls->generate('dashboard_operator_setup_step', ['step' => $k]),
                $state ?? 'todo', match ($state) { 'done' => 'done', 'skipped' => 'skipped', default => 'to do' },
                match ($state) { 'done' => 'check-circle-fill', 'skipped' => 'dash-circle', default => 'circle' });
        }
        $views[] = $this->view('Checked automatically', $this->urls->generate('dashboard_operator_setup', ['_fragment' => 'sg-auto']), icon: 'gear-wide-connected');
        $done = \count(array_filter(SetupWizard::NEEDS_YOU, static fn (string $k): bool => 'done' === ($steps[$k]['state'] ?? null)));

        return [$views, $done < \count(SetupWizard::NEEDS_YOU) ? $done.' of '.\count(SetupWizard::NEEDS_YOU) : null];
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
