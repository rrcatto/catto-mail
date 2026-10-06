<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Access\PermissionCatalog;
use App\Domain\DomainRuleViolation;
use App\Enum\ClientMembershipRole;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;

/**
 * Roles, permissions and the ACL (specification 2.7): permission keys decide
 * every operator page; ADMIN holds everything and cannot be reduced; only ADMIN
 * manages roles and grants ADMIN; changes apply at the next request; users,
 * roles and memberships are managed in the browser and audited.
 */
final class AccessControlTest extends DashboardTestCase
{
    private const PAGES = [
        'PLATFORM.OVERVIEW.VIEW' => '/dashboard/operator',
        'PLATFORM.CLIENT.VIEW' => '/dashboard/operator/clients',
        'PLATFORM.USER.VIEW' => '/dashboard/operator/users',
        'PLATFORM.DSN.VIEW' => '/dashboard/operator/unmatched-dsns',
        'PLATFORM.SUPPRESSION.VIEW' => '/dashboard/operator/suppressions',
        'PLATFORM.AUDIT.VIEW' => '/dashboard/operator/audit',
        'PLATFORM.WEBHOOK.VIEW' => '/dashboard/operator/webhooks',
        'SYSTEM.ROLE.VIEW' => '/dashboard/operator/roles',
    ];

    private function admin(): \App\Entity\User
    {
        return $this->newUser(false, null, ClientMembershipRole::Viewer, 'ADMIN');
    }

    public function testEachOperatorPageNeedsItsPermissionAndChangesApplyAtTheNextRequest(): void
    {
        $admin = $this->admin();
        $role = $this->acl()->createRole('AUDITOR_'.strtoupper(bin2hex(random_bytes(3))), 'Auditor', 'Reads the audit log', $this->reload($admin));
        $this->acl()->setRolePermissions($role, ['PLATFORM.AUDIT.VIEW'], $this->reload($admin));
        $auditor = $this->newUser(false, null, ClientMembershipRole::Viewer, $role->getRoleKey());
        $this->signIn($auditor);
        foreach (self::PAGES as $permission => $uri) {
            self::assertSame('PLATFORM.AUDIT.VIEW' === $permission ? 200 : 403, $this->page($uri)->getStatusCode(), $uri);
        }
        $nav = (string) $this->page('/dashboard/operator/audit')->getContent();
        self::assertStringContainsString('/dashboard/operator/audit', $nav);
        self::assertStringNotContainsString('href="/dashboard/operator/clients"', $nav, 'navigation shows only permitted pages');

        $this->acl()->setRolePermissions($this->acl()->role($role->getId()->toRfc4122()), ['PLATFORM.AUDIT.VIEW', 'PLATFORM.CLIENT.VIEW'], $this->reload($admin));
        self::assertSame(200, $this->page('/dashboard/operator/clients')->getStatusCode(), 'granted at the next request, no new sign-in');
        $this->acl()->setRolePermissions($this->acl()->role($role->getId()->toRfc4122()), [], $this->reload($admin));
        self::assertSame(403, $this->page('/dashboard/operator/audit')->getStatusCode(), 'revoked at the next request');
        self::assertContains('acl.role_permissions_updated', Db::owner()->fetchFirstColumn('SELECT action FROM audit_log WHERE target_id = ?', [$role->getId()->toRfc4122()]));
    }

    public function testAdminHoldsEverythingAndTheRulesHold(): void
    {
        $admin = $this->admin();
        $acl = $this->acl();
        self::assertSame(PermissionCatalog::keys(), $acl->resolve($this->reload($admin))->getPermissions());
        $adminRole = $acl->roleByKey('ADMIN');
        try {
            $acl->setRolePermissions($adminRole, [], $this->reload($admin));
            self::fail('ADMIN reduced');
        } catch (DomainRuleViolation) {
        }
        $custom = $acl->createRole('CUSTOM_'.strtoupper(bin2hex(random_bytes(3))), 'Custom', '', $this->reload($admin));
        try {
            $acl->setRolePermissions($custom, ['SYSTEM.ROLE.MANAGE'], $this->reload($admin));
            self::fail('SYSTEM permission given to a custom role');
        } catch (DomainRuleViolation) {
        }
        try {
            $acl->setRolePermissions($custom, ['NOT.A.PERMISSION'], $this->reload($admin));
            self::fail('unknown permission');
        } catch (DomainRuleViolation) {
        }
        try {
            $acl->revokeRole($this->reload($admin), $adminRole, $this->reload($admin));
            self::fail('own ADMIN revoked');
        } catch (DomainRuleViolation) {
        }
        $holder = $this->newUser(false, null, ClientMembershipRole::Viewer, $custom->getRoleKey());
        try {
            $acl->deleteRole($custom, $this->reload($admin));
            self::fail('role in use deleted');
        } catch (DomainRuleViolation) {
        }
        self::assertTrue($acl->revokeRole($this->reload($holder), $custom, $this->reload($admin)));
        $acl->deleteRole($acl->role($custom->getId()->toRfc4122()), $this->reload($admin));
        self::assertNull($acl->roleByKey($custom->getRoleKey()));
        try {
            $acl->deleteRole($acl->roleByKey('OPERATOR'), $this->reload($admin));
            self::fail('built-in role deleted');
        } catch (DomainRuleViolation) {
        }
    }

    public function testOperatorsCannotGrantAdminOrManageRoles(): void
    {
        $operator = $this->newUser(true);
        $target = $this->newUser();
        $this->signIn($operator);
        self::assertSame(403, $this->page('/dashboard/operator/roles')->getStatusCode(), 'roles are ADMIN business');
        $page = '/dashboard/operator/users/'.$target->getId()->toRfc4122();
        $crawler = $this->crawler($page);
        $adminId = $this->acl()->roleByKey('ADMIN')->getId()->toRfc4122();
        self::assertSame(0, $crawler->filter("input[name=\"role_id\"][value=\"$adminId\"]")->count(), 'no ADMIN button for operators');
        $token = $crawler->filter('form[action="'.$page.'/roles"] input[name="_token"]')->first()->attr('value');
        $this->browser->request('POST', "$page/roles", ['role_id' => $adminId, 'action' => 'grant', '_token' => $token]);
        self::assertSame([], Db::owner()->fetchFirstColumn('SELECT role_id FROM user_roles WHERE user_id = ?', [$target->getId()->toRfc4122()]), 'a forged ADMIN grant is refused');
        $this->browser->request('POST', '/dashboard/operator/roles', ['role_key' => 'X_ROLE', 'name' => 'x', '_token' => $token]);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
    }

    public function testUsersAreManagedInTheBrowser(): void
    {
        $client = $this->newClient();
        $operator = $this->newUser(true);
        $this->signIn($operator);
        $email = 'new.'.bin2hex(random_bytes(4)).'@example.test';
        $operatorRole = $this->acl()->roleByKey('OPERATOR')->getId()->toRfc4122();
        $r = $this->submit('/dashboard/operator/users', '/dashboard/operator/users', ['email' => $email, 'display_name' => 'New Person',
            'role_id' => '', 'client_id' => $client->getId()->toRfc4122(), 'membership_role' => 'member', 'send_link' => '1']);
        self::assertSame(302, $r->getStatusCode());
        self::assertCount(1, self::getMailerMessages(), 'a sign-in link was emailed to the new user');
        self::assertSame($email, self::getMailerMessages()[0]->getTo()[0]->getAddress());
        $id = (string) Db::owner()->fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
        self::assertSame('member', Db::owner()->fetchOne('SELECT role FROM client_memberships WHERE user_id = ? AND client_id = ?', [$id, $client->getId()->toRfc4122()]));
        $page = "/dashboard/operator/users/$id";

        $this->submit($page, "$page/roles", ['role_id' => $operatorRole, 'action' => 'grant']);
        self::assertSame(['OPERATOR'], Db::owner()->fetchFirstColumn('SELECT r.role_key FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$id]));
        $this->submit($page, "$page/roles", ['role_id' => $operatorRole, 'action' => 'revoke']);
        self::assertSame([], Db::owner()->fetchFirstColumn('SELECT role_id FROM user_roles WHERE user_id = ?', [$id]));

        $crawler = $this->crawler($page);
        $token = $crawler->filter('form[action="'.$page.'/memberships"] input[name="_token"]')->first()->attr('value');
        $this->browser->request('POST', "$page/memberships", ['client_id' => $client->getId()->toRfc4122(), 'action' => 'remove', '_token' => $token]);
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM client_memberships WHERE user_id = ?', [$id]));
        $this->submit($page, "$page/profile", ['display_name' => 'Renamed', 'status' => 'disabled']);
        self::assertSame(['Renamed', 'disabled'], array_values(Db::owner()->fetchAssociative('SELECT display_name, status FROM users WHERE id = ?', [$id])));

        $actions = Db::owner()->fetchFirstColumn("SELECT action FROM audit_log WHERE actor_id = ? ORDER BY occurred_at", [$operator->getId()->toRfc4122()]);
        foreach (['user.created', 'client_membership.created', 'user.role_granted', 'user.role_revoked', 'client_membership.removed', 'user.display_name_changed', 'user.disabled'] as $a) {
            self::assertContains($a, $actions);
        }
        // An operator cannot disable themselves.
        $self = '/dashboard/operator/users/'.$operator->getId()->toRfc4122();
        $token = $this->crawler($self)->filter('form[action="'.$self.'/profile"] input[name="_token"]')->attr('value');
        $this->browser->request('POST', "$self/profile", ['display_name' => '', 'status' => 'disabled', '_token' => $token]);
        self::assertSame('active', Db::owner()->fetchOne('SELECT status FROM users WHERE id = ?', [$operator->getId()->toRfc4122()]));
    }

    public function testAdminManagesRolesInTheBrowser(): void
    {
        $this->signIn($this->admin());
        $key = 'SUPPORT_'.strtoupper(bin2hex(random_bytes(3)));
        $r = $this->submit('/dashboard/operator/roles', '/dashboard/operator/roles', ['role_key' => strtolower($key), 'name' => 'Support', 'description' => 'Helps clients']);
        $location = (string) $r->headers->get('Location');
        self::assertMatchesRegularExpression('#/dashboard/operator/roles/[0-9a-f-]{36}$#', $location);
        $this->submit($location, $location, ['name' => 'Support desk', 'description' => 'Helps clients', 'permissions' => ['PLATFORM.CLIENT.VIEW', 'PLATFORM.USER.VIEW']]);
        $role = $this->acl()->roleByKey($key);
        self::assertSame('Support desk', $role->getName());
        self::assertSame(['PLATFORM.CLIENT.VIEW', 'PLATFORM.USER.VIEW'], $this->acl()->permissionsOfRole($role));
        $adminPage = '/dashboard/operator/roles/'.$this->acl()->roleByKey('ADMIN')->getId()->toRfc4122();
        self::assertSame(0, $this->crawler($adminPage)->filter('button[type="submit"]:contains("Save role")')->count(), 'ADMIN cannot be edited');
        $this->submit($location, $location.'/delete');
        self::assertNull($this->acl()->roleByKey($key));
    }
}
