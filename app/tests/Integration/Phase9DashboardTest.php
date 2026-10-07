<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Client\ClientLifecycle;
use App\Enum\ClientMembershipRole;
use App\Enum\ClientStatus;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use App\Tests\Support\Phase9Fixtures;

/**
 * Phase 9 dashboard flows and permissions: the operator client page (lifecycle with
 * reasons and per-transition permissions, limits, notes, policy, API keys), the client's
 * own API keys and policy acceptance, the alerts and usage pages, and that each action
 * needs its permission key or client role.
 */
final class Phase9DashboardTest extends DashboardTestCase
{
    public function testOperatorApprovesAndRestrictsWithReasons(): void
    {
        $client = $this->container()->get(ClientLifecycle::class)->create('Approval Co '.bin2hex(random_bytes(3)), 'ops@approval.example', 'standard',
            ClientStatus::PendingApproval, self::actor(), false);
        $id = $client->getId()->toRfc4122();
        $this->signIn($this->newUser(true));
        $page = '/dashboard/operator/clients/'.$id;
        $html = self::text($this->page($page));
        foreach (['Lifecycle', 'Service policy', 'Limits and quotas', 'Reputation', 'API keys', 'Private notes', 'Audit history', 'Approve', 'Reject and close'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        self::assertStringContainsString('pending approval', strtolower(self::text($this->page('/dashboard/operator/clients?status=pending_approval&sort=status_changed&dir=asc'))));

        $action = "/dashboard/operator/clients/$id/status";
        self::assertSame(302, $this->submit($page, $action, ['status' => 'active', 'note' => ''])->getStatusCode());
        self::assertSame('pending_approval', Db::owner()->fetchOne('SELECT status FROM clients WHERE id = ?', [$id]), 'a reason is required');
        $this->submit($page, $action, ['status' => 'active', 'note' => 'reviewed and approved']);
        $this->submit($page, $action, ['status' => 'suspended', 'note' => 'complaint spike']);
        self::assertSame('suspended', Db::owner()->fetchOne('SELECT status FROM clients WHERE id = ?', [$id]));
        self::assertSame(['client.approved', 'client.suspended'], Db::owner()->fetchFirstColumn(
            "SELECT action FROM audit_log WHERE target_id = ? AND action IN ('client.approved', 'client.suspended') ORDER BY occurred_at", [$id]));
        $html = self::text($this->page($page));
        self::assertStringContainsString('complaint spike', $html, 'the reason appears in the audit history');

        // Limits, notes and the key lifecycle.
        $this->submit($page, "/dashboard/operator/clients/$id/limits", ['send_recipients_per_day' => '5000', 'max_api_keys' => '3', 'note' => 'plan S']);
        self::assertSame(5000, (int) Db::owner()->fetchOne('SELECT send_recipients_per_day FROM client_limits WHERE client_id = ?', [$id]));
        $this->submit($page, "/dashboard/operator/clients/$id/notes", ['note' => 'Contract signed 2026-10-01; abuse contact verified by phone.']);
        self::assertStringContainsString('abuse contact verified by phone', self::text($this->page($page)));
        $r = $this->submit($page, "/dashboard/operator/clients/$id/api-keys", ['name' => 'production server', 'expires_on' => gmdate('Y-m-d', strtotime('+90 days'))]);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame(1, preg_match('/shk_[A-Za-z0-9_-]{43}/', (string) $r->getContent(), $m));
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        self::assertStringNotContainsString($m[0], (string) $this->page($page)->getContent(), 'shown once only');
        $keyId = Db::owner()->fetchOne('SELECT id FROM api_keys WHERE client_id = ?', [$id]);
        $this->submit($page, "/dashboard/operator/clients/$id/api-keys/$keyId/revoke");
        self::assertNotNull(Db::owner()->fetchOne('SELECT revoked_at FROM api_keys WHERE id = ?', [$keyId]));
    }

    public function testEachActionNeedsItsPermission(): void
    {
        $client = $this->newClient();
        $id = $client->getId()->toRfc4122();
        $viewer = $this->newUser(false, null, roleKey: $this->role(['PLATFORM.CLIENT.VIEW']));
        $this->signIn($viewer);
        $page = '/dashboard/operator/clients/'.$id;
        $html = self::text($this->page($page));
        self::assertStringContainsString('needs PLATFORM.CLIENT.RESTRICT', $html);
        self::assertStringNotContainsString('Change limits', $html);
        // The permission check answers 403 before the form (or its CSRF token) is even read.
        foreach (['/limits' => ['note' => 'x'], '/notes' => ['note' => 'x'], '/api-keys' => [], '/account' => []] as $suffix => $fields) {
            $this->browser->request('POST', $page.$suffix, $fields + ['_token' => 'any']);
            self::assertSame(403, $this->browser->getResponse()->getStatusCode(), $suffix);
        }
        foreach (['/dashboard/operator/alerts', '/dashboard/operator/usage'] as $uri) {
            self::assertSame(403, $this->page($uri)->getStatusCode(), $uri);
        }
        self::assertSame('active', Db::owner()->fetchOne('SELECT status FROM clients WHERE id = ?', [$id]));
    }

    public function testClientAdminsManageKeysAndAcceptThePolicy(): void
    {
        $client = $this->container()->get(ClientLifecycle::class)->create('Self Service '.bin2hex(random_bytes(3)), 'ops@self.example', 'standard',
            ClientStatus::PendingApproval, self::actor(), true);
        $id = $client->getId()->toRfc4122();
        $viewer = $this->newUser(false, $client, ClientMembershipRole::Viewer);
        $this->signIn($viewer);
        $html = self::text($this->page("/dashboard/c/$id"));
        self::assertStringContainsString('awaits approval', $html);
        self::assertStringContainsString('Ask a client admin to accept it', $html);
        self::assertStringContainsString('Client admins create and revoke keys', self::text($this->page("/dashboard/c/$id/api-keys")));
        $this->browser->request('POST', "/dashboard/c/$id/api-keys", ['_token' => 'x']);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode(), 'a viewer may not create keys (like a foreign client)');

        $admin = $this->newUser(false, $client, ClientMembershipRole::Admin);
        $this->browser->getCookieJar()->clear();
        $this->signIn($admin);
        $this->submit("/dashboard/c/$id", "/dashboard/c/$id/policy/accept", ['policy_version' => 'aup-test-1']);
        self::assertSame('client_dashboard', Db::owner()->fetchOne("SELECT source FROM client_policy_acceptances WHERE client_id = ? AND policy_version = 'aup-test-1'", [$id]));
        self::assertSame($admin->getId()->toRfc4122(), Db::owner()->fetchOne('SELECT accepted_by_user_id FROM client_policy_acceptances WHERE client_id = ?', [$id]));
        $this->container()->get(ClientLifecycle::class)->changeStatus($this->reload($client), ClientStatus::Active, self::actor(), 'approved after acceptance');
        $r = $this->submit("/dashboard/c/$id/api-keys", "/dashboard/c/$id/api-keys", ['name' => 'ci']);
        self::assertSame(1, preg_match('/shk_[A-Za-z0-9_-]{43}/', (string) $r->getContent()));
        $html = self::text($this->page("/dashboard/c/$id/api-keys"));
        self::assertStringContainsString('ci', $html);
        self::assertStringContainsString('Usable keys: 1 of at most 10', $html);
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'api_key.created' AND actor_id = ?", [$admin->getId()->toRfc4122()]));
    }

    public function testAlertsAndUsagePages(): void
    {
        $client = $this->newClient(ClientStatus::Active, 'Metered Co');
        $id = $client->getId()->toRfc4122();
        $recent = gmdate('Y-m-d H:i:s', time() - 600);
        $send = Phase9Fixtures::meteredSendJob(Db::owner(), $id, $this->verifiedDomain($client)->getId()->toRfc4122(), 120, $recent);
        Phase9Fixtures::events(Db::owner(), \array_slice($send['messages'], 0, 12), 'hard_bounce', $recent, 'recipient');
        $operator = $this->newUser(true);
        $this->signIn($operator);
        $this->submit('/dashboard/operator/alerts', '/dashboard/operator/alerts/evaluate');
        $html = self::text($this->page('/dashboard/operator/alerts?client='.$id));
        self::assertStringContainsString('Hard-bounce rate', $html);
        self::assertStringContainsString('12.00 / 120.00', $html, 'numerator and denominator');
        $alert = Db::owner()->fetchOne("SELECT id FROM client_alerts WHERE client_id = ? AND metric = 'hard_bounce_rate' AND window_hours = 24", [$id]);
        $this->submit('/dashboard/operator/alerts?client='.$id, "/dashboard/operator/alerts/$alert/acknowledge", ['note' => 'throttled the client']);
        self::assertSame('throttled the client', Db::owner()->fetchOne('SELECT acknowledgement_note FROM client_alerts WHERE id = ?', [$alert]));

        $usage = self::text($this->page('/dashboard/operator/usage?period=current_month'));
        self::assertStringContainsString('Metered Co', $usage);
        $r = $this->submit('/dashboard/operator/usage?period=current_month', "/dashboard/operator/usage/clients/$id/export",
            ['from' => gmdate('Y-m-01'), 'to' => gmdate('Y-m-d', strtotime('first day of next month')), 'format' => 'csv']);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString("$id,", (string) $r->getContent());
        self::assertStringContainsString('message_submitted,120,120,usage_records,consistent', (string) $r->getContent());
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'usage.exported' AND target_id = ? AND actor_id = ?",
            [$id, $operator->getId()->toRfc4122()]));
        $r = $this->submit('/dashboard/operator/usage?period=current_month', "/dashboard/operator/usage/clients/$id/reconcile",
            ['from' => gmdate('Y-m-01'), 'to' => gmdate('Y-m-d', strtotime('first day of next month'))]);
        self::assertStringContainsString('Consistent', self::text($r));
    }

    /** @param list<string> $permissions */
    private function role(array $permissions): string
    {
        $key = 'P9D_'.strtoupper(bin2hex(random_bytes(3)));
        $admin = $this->newUser(false, null, roleKey: 'ADMIN');
        $this->acl()->createRole($key, 'Phase 9 dashboard '.$key, '', $this->reload($admin));
        $this->acl()->setRolePermissions($this->acl()->roleByKey($key), $permissions, $this->reload($admin));

        return $key;
    }
}
