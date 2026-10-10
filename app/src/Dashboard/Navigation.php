<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Entity\Client;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The dashboard navigation: the round area buttons on the left and the page pills at
 * the top. Operators see the operator console's areas, client members (and operators
 * viewing a client) the client workspace's. Every page keeps its permission key: an
 * area or pill appears only when the user may open it, and an area links to its first
 * page the user may open. Badges count what needs action, only where the user may look.
 */
final class Navigation
{
    /**
     * Operator areas: key => [label, icon, pages]; a page is [section, label, route, permission].
     */
    private const OPERATOR = [
        'overview' => ['Overview', 'overview', [
            ['overview', 'Overview', 'dashboard_operator_overview', 'PLATFORM.OVERVIEW.VIEW'],
        ]],
        'mail' => ['Mail flow', 'mail', [
            ['system', 'Delivery', 'dashboard_operator_system', 'PLATFORM.SYSTEM.VIEW'],
            ['suppressions', 'Suppressions', 'dashboard_operator_suppressions', 'PLATFORM.SUPPRESSION.VIEW'],
            ['dsns', 'Unmatched DSNs', 'dashboard_operator_dsns', 'PLATFORM.DSN.VIEW'],
            ['webhooks', 'Webhooks', 'dashboard_operator_webhooks', 'PLATFORM.WEBHOOK.VIEW'],
        ]],
        'clients' => ['Clients', 'clients', [
            ['clients', 'Clients', 'dashboard_operator_clients', 'PLATFORM.CLIENT.VIEW'],
            ['alerts', 'Abuse & reputation', 'dashboard_operator_alerts', 'PLATFORM.ABUSE.VIEW'],
            ['usage', 'Usage & billing', 'dashboard_operator_usage', 'PLATFORM.USAGE.VIEW'],
            ['batches', 'Address batches', 'dashboard_operator_batches', 'SYSTEM.ADDRESS_BATCH.MANAGE'],
        ]],
        'system' => ['System', 'system', [
            ['setup', 'System setup', 'dashboard_operator_setup', 'SYSTEM.SETUP.MANAGE'],
            ['diagnostics', 'Diagnostics', 'dashboard_operator_diagnostics', 'PLATFORM.SYSTEM.VIEW'],
            ['audit', 'Audit log', 'dashboard_operator_audit', 'PLATFORM.AUDIT.VIEW'],
        ]],
        'access' => ['Access', 'access', [
            ['users', 'Users', 'dashboard_operator_users', 'PLATFORM.USER.VIEW'],
            ['roles', 'Roles & permissions', 'dashboard_operator_roles', 'SYSTEM.ROLE.VIEW'],
        ]],
    ];

    /** Client workspace areas: key => [label, icon, pages]; a page is [section, label, route]. */
    private const CLIENT = [
        'overview' => ['Overview', 'overview', [['overview', 'Overview', 'dashboard_client_overview']]],
        'sending' => ['Sending', 'send', [
            ['sending', 'Send jobs', 'dashboard_client_send_jobs'],
            ['suppressions', 'Suppressions', 'dashboard_client_suppressions'],
        ]],
        'validation' => ['Validation', 'check', [['validation', 'Validation jobs', 'dashboard_client_validation_jobs']]],
        'settings' => ['Settings', 'settings', [
            ['domains', 'Sending domains', 'dashboard_client_domains'],
            ['api_keys', 'API keys', 'dashboard_client_api_keys'],
            ['webhooks', 'Webhooks', 'dashboard_client_webhooks'],
        ]],
        'usage' => ['Usage', 'usage', [['usage', 'Usage & limits', 'dashboard_client_usage']]],
    ];

    /** @var array<string, int>|null */
    private ?array $counts = null;

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
        private readonly OperatorReadModel $read,
    ) {
    }

    /**
     * @return array{context: ?string, areas: list<array<string, mixed>>, pills: list<array<string, mixed>>,
     *               help: ?string, switch: ?array{href: string, label: string}, attention: int}
     */
    public function build(?Client $client, string $section): array
    {
        $user = $this->security->getUser();
        $nav = ['context' => null, 'areas' => [], 'pills' => [], 'help' => null, 'switch' => null, 'attention' => 0];
        if (!$user instanceof User) {
            return $nav;
        }
        $platform = $user->isPlatformUser();
        $badges = $platform ? $this->badges() : [];
        $nav['attention'] = $platform ? array_sum(array_map(static fn (array $b): int => $b[0], array_filter($badges, static fn (array $b, string $k): bool => !str_contains($k, '.'), \ARRAY_FILTER_USE_BOTH))) : 0;
        if ($platform && $this->security->isGranted('PLATFORM.HELP.VIEW')) {
            $nav['help'] = $this->urls->generate('dashboard_operator_help');
        }

        if (null !== $client) {
            $nav['context'] = 'client';
            $params = ['clientId' => $client->getId()->toRfc4122()];
            foreach (self::CLIENT as $key => [$label, $icon, $pages]) {
                $current = \in_array($section, array_column($pages, 0), true);
                $nav['areas'][] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'current' => $current,
                    'href' => $this->urls->generate($pages[0][2], $params), 'badge' => null];
                if ($current) {
                    foreach ($pages as [$s, $l, $route]) {
                        $nav['pills'][] = ['label' => $l, 'href' => $this->urls->generate($route, $params), 'current' => $s === $section, 'badge' => null];
                    }
                }
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
            $allowed = array_values(array_filter($pages, fn (array $p): bool => $this->security->isGranted($p[3])));
            if ([] === $allowed) {
                continue;
            }
            $current = \in_array($section, array_column($allowed, 0), true);
            $nav['areas'][] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'current' => $current,
                'href' => $this->urls->generate($allowed[0][2]), 'badge' => $badges[$key] ?? null];
            if ($current) {
                foreach ($allowed as [$s, $l, $route]) {
                    $nav['pills'][] = ['label' => $l, 'href' => $this->urls->generate($route), 'current' => $s === $section, 'badge' => $badges["$key.$s"] ?? null];
                }
            }
        }

        return $nav;
    }

    /**
     * Badges as [count, tone] by area key, and by "area.section" for pills; only counts the
     * user may look at, and only non-zero ones.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    private function badges(): array
    {
        $c = $this->counts ??= $this->read->navCounts();
        $can = fn (string $permission): bool => $this->security->isGranted($permission);
        $alerts = $can('PLATFORM.ABUSE.VIEW') ? $c['open_alerts'] : 0;
        $pending = $can('PLATFORM.CLIENT.VIEW') ? $c['pending_clients'] : 0;
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
