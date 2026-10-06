<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Access\AccessControl;
use App\Access\PermissionCatalog;
use App\Domain\DomainRuleViolation;
use App\Enum\ClientMembershipRole;
use App\Security\ClientVoter;
use App\Security\LoginLinkService;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Dashboard users (D-12) and passwordless sign-in (specification 2.7): single-use
 * emailed links, the configured administrator, disabled users, rate limits,
 * memberships and the client voter.
 */
final class DashboardUserTest extends DashboardTestCase
{
    private static function email(string $local = 'User'): string
    {
        return $local.'.'.bin2hex(random_bytes(4)).'@Example.test';
    }

    public function testLoginEmailIsUniqueCaseInsensitively(): void
    {
        $email = self::email();
        $user = $this->accounts()->createUser($email, null, self::actor());
        self::assertSame($email, $user->getEmail(), 'stored as entered');
        $this->expectException(DomainRuleViolation::class);
        $this->accounts()->createUser(strtolower($email), null, self::actor());
    }

    public function testDatabaseEnforcesCaseInsensitiveUniquenessToo(): void
    {
        $email = self::email();
        $this->accounts()->createUser($email, null, self::actor());
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        Db::app()->insert('users', ['id' => \Symfony\Component\Uid\Uuid::v7()->toRfc4122(), 'email' => strtoupper($email)]);
    }

    public function testMembershipsAndVoter(): void
    {
        $client = $this->newClient();
        $other = $this->newClient();
        $viewer = $this->newUser(false, $client, ClientMembershipRole::Viewer);
        $member = $this->newUser(false, $client, ClientMembershipRole::Member);
        $admin = $this->newUser(false, $client, ClientMembershipRole::Viewer);
        $m = $this->accounts()->setMembership($this->reload($admin), $this->reload($client), ClientMembershipRole::Admin, self::actor());
        $operator = $this->newUser(true);
        $reader = $this->newUser(false, null, ClientMembershipRole::Viewer, null);
        $role = $this->acl()->roleByKey('OPERATOR');
        self::assertNotNull($role);
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM client_memberships WHERE user_id = ?', [$admin->getId()->toRfc4122()]));
        self::assertContains('client_membership.role_changed', Db::owner()->fetchFirstColumn('SELECT action FROM audit_log WHERE target_id = ?', [$m->getId()->toRfc4122()]));

        $acl = $this->acl();
        $readOnly = $acl->createRole('READER_'.strtoupper(bin2hex(random_bytes(3))), 'Reads clients', '', $this->reload($this->adminUser()));
        $acl->setRolePermissions($readOnly, ['PLATFORM.CLIENT.VIEW'], $this->reload($this->adminUser()));
        $acl->grantRoleAsSystem($this->reload($reader), $readOnly->getRoleKey(), self::actor());

        $voter = new ClientVoter($this->container()->get('doctrine')->getManager());
        $can = function ($user, string $attr, $c) use ($acl, $voter): bool {
            $u = $acl->resolve($this->reload($user));

            return 1 === $voter->vote(new UsernamePasswordToken($u, 'dashboard', $u->getRoles()), $this->reload($c), [$attr]);
        };
        self::assertTrue($can($viewer, ClientVoter::VIEW, $client));
        self::assertFalse($can($viewer, ClientVoter::OPERATE, $client));
        self::assertTrue($can($member, ClientVoter::OPERATE, $client));
        self::assertFalse($can($member, ClientVoter::ADMIN, $client));
        self::assertTrue($can($admin, ClientVoter::ADMIN, $client));
        self::assertFalse($can($admin, ClientVoter::VIEW, $other), 'membership is per client');
        self::assertTrue($can($operator, ClientVoter::ADMIN, $other), 'PLATFORM.CLIENT.MANAGE acts in every client');
        self::assertTrue($can($reader, ClientVoter::VIEW, $other), 'PLATFORM.CLIENT.VIEW reads every client');
        self::assertFalse($can($reader, ClientVoter::OPERATE, $other), '... but cannot act');

        $this->accounts()->setDisabled($this->reload($admin), true, self::actor());
        self::assertFalse($can($admin, ClientVoter::VIEW, $client), 'disabled users get nothing');
    }

    public function testSignInWithAnEmailedSingleUseLink(): void
    {
        $email = self::email();
        $user = $this->accounts()->createUser($email, 'Ann', self::actor());
        $path = $this->requestLink(strtoupper($email));
        self::assertNotNull($path, 'the login email is matched case-insensitively');
        $hash = hash('sha256', substr($path, (int) strpos($path, '=') + 1));
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM auth_login_tokens WHERE token_hash = ? AND used_at IS NULL', [$hash]),
            'only the hash of the token is stored');

        $this->browser->request('HEAD', $path);
        self::assertSame(204, $this->browser->getResponse()->getStatusCode());
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM auth_login_tokens WHERE token_hash = ? AND used_at IS NULL', [$hash]),
            'a HEAD request (mail scanner) never consumes the link');

        $this->browser->request('GET', $path);
        self::assertStringEndsWith('/dashboard', (string) $this->browser->getResponse()->headers->get('Location'));
        $this->browser->request('GET', '/dashboard');
        self::assertStringContainsString('Signed in as', (string) $this->browser->getResponse()->getContent());
        self::assertNotNull(Db::owner()->fetchOne('SELECT last_login_at FROM users WHERE id = ?', [$user->getId()->toRfc4122()]));
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'auth.login' AND target_id = ?", [$user->getId()->toRfc4122()]));

        // A dashboard session is not an API credential.
        $this->browser->request('GET', '/v1/send-jobs/01999999-0000-7000-8000-000000000000');
        self::assertSame(401, $this->browser->getResponse()->getStatusCode());

        // The link works once.
        $this->browser->getCookieJar()->clear();
        $this->browser->request('GET', $path);
        self::assertStringEndsWith('/dashboard/login', (string) $this->browser->getResponse()->headers->get('Location'));
        $this->browser->followRedirect();
        self::assertStringContainsString('invalid, has expired or was already used', (string) $this->browser->getResponse()->getContent());
        self::assertStringEndsWith('/dashboard/login', (string) $this->page('/dashboard')->headers->get('Location'), 'not signed in');
    }

    public function testExpiredAndMalformedLinksAreRefused(): void
    {
        $user = $this->accounts()->createUser(self::email(), null, self::actor());
        $path = $this->requestLink($user->getEmail());
        Db::owner()->executeStatement("UPDATE auth_login_tokens SET expires_at = created_at + interval '1 second', created_at = created_at - interval '1 hour' WHERE user_id = ?", [$user->getId()->toRfc4122()]);
        Db::owner()->executeStatement("UPDATE auth_login_tokens SET expires_at = now() - interval '1 minute' WHERE user_id = ?", [$user->getId()->toRfc4122()]);
        $this->browser->request('GET', (string) $path);
        self::assertStringEndsWith('/dashboard/login', (string) $this->browser->getResponse()->headers->get('Location'));
        foreach (['/dashboard/login/verify', '/dashboard/login/verify?token=short', '/dashboard/login/verify?token='.str_repeat('A', 43)] as $p) {
            $this->browser->request('GET', $p);
            self::assertStringEndsWith('/dashboard/login', (string) $this->browser->getResponse()->headers->get('Location'), $p);
        }
        self::assertStringEndsWith('/dashboard/login', (string) $this->page('/dashboard')->headers->get('Location'));
    }

    public function testUnknownDisabledAndApiKeyAddressesGetNoLinkAndTheSameAnswer(): void
    {
        self::assertNull($this->requestLink(self::email('nobody')), 'no account, no email');
        $disabled = $this->accounts()->createUser(self::email(), null, self::actor());
        $this->accounts()->setDisabled($disabled, true, self::actor());
        self::assertNull($this->requestLink($disabled->getEmail()));
        [, $raw] = $this->newApiClient();
        self::assertNull($this->requestLink($raw), 'an API key is not an email address and never signs in');
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM auth_login_tokens WHERE lower(email) = lower(?)', [$disabled->getEmail()]));
    }

    public function testDisablingAUserEndsTheirSession(): void
    {
        $client = $this->newClient();
        $user = $this->newUser(false, $client);
        $this->signIn($user);
        self::assertSame(200, $this->page('/dashboard/c/'.$client->getId()->toRfc4122())->getStatusCode());
        $this->accounts()->setDisabled($this->reload($user), true, self::actor());
        self::assertStringEndsWith('/dashboard/login', (string) $this->page('/dashboard/c/'.$client->getId()->toRfc4122())->headers->get('Location'));
    }

    public function testTheConfiguredAdministratorIsCreatedAndAlwaysGetsAdmin(): void
    {
        $admin = 'boss.'.bin2hex(random_bytes(4)).'@example.test';
        $this->withEnv(['APP_ADMIN_EMAIL' => $admin], function () use ($admin): void {
            self::assertFalse(Db::owner()->fetchOne('SELECT id FROM users WHERE email = ?', [$admin]));
            $path = $this->requestLink($admin);
            self::assertNotNull($path, 'the configured administrator can request a link before the account exists');
            $this->browser->request('GET', (string) $path);
            self::assertStringEndsWith('/dashboard', (string) $this->browser->getResponse()->headers->get('Location'));
            $id = (string) Db::owner()->fetchOne('SELECT id FROM users WHERE email = ?', [$admin]);
            self::assertSame(['ADMIN'], Db::owner()->fetchFirstColumn('SELECT r.role_key FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$id]));
            self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'user.role_granted' AND target_id = ? AND detail_json->>'reason' = 'APP_ADMIN_EMAIL'", [$id]));
            self::assertSame(200, $this->page('/dashboard/operator/roles')->getStatusCode(), 'ADMIN sees the roles page');
            $text = self::text($this->page('/dashboard/operator/roles'));
            self::assertStringContainsString(\count(PermissionCatalog::keys()).' / '.\count(PermissionCatalog::keys()), $text, 'ADMIN holds every permission');

            // Even if ADMIN is taken away (or its row lost), the next sign-in restores it.
            Db::owner()->executeStatement('DELETE FROM user_roles WHERE user_id = ?', [$id]);
            $this->browser->getCookieJar()->clear();
            $path = $this->requestLink(strtoupper($admin));
            $this->browser->request('GET', (string) $path);
            self::assertSame(['ADMIN'], Db::owner()->fetchFirstColumn('SELECT r.role_key FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$id]));
        });
    }

    public function testRequestsAreRateLimitedPerAddress(): void
    {
        $user = $this->accounts()->createUser(self::email(), null, self::actor());
        for ($i = 0; $i < LoginLinkService::MAX_PER_EMAIL; ++$i) {
            self::assertNotNull($this->requestLink($user->getEmail()), "request $i");
        }
        self::assertNull($this->requestLink($user->getEmail()), 'over the per-address limit: no email, same answer');
    }

    public function testRequestsAreRateLimitedPerClientAddress(): void
    {
        $ip = '10.78.'.random_int(0, 255).'.'.random_int(1, 254);
        for ($i = 0; $i < LoginLinkService::MAX_PER_IP; ++$i) {
            self::assertNotNull($this->requestLink($this->accounts()->createUser(self::email(), null, self::actor())->getEmail(), $ip));
        }
        self::assertNull($this->requestLink($this->accounts()->createUser(self::email(), null, self::actor())->getEmail(), $ip));
    }

    public function testLinkIsBuiltFromTheConfiguredUrlNotTheHostHeader(): void
    {
        $user = $this->accounts()->createUser(self::email(), null, self::actor());
        // requestLink() asserts the link starts with the configured SMARTHOST_PUBLIC_BASE_URL.
        self::assertNotNull($this->requestLink($user->getEmail(), null, ['HTTP_HOST' => 'evil.example.test', 'HTTP_X_FORWARDED_HOST' => 'evil.example.test']));
    }

    public function testRequestFormNeedsCsrfAndSignInReturnsToTheRequestedPage(): void
    {
        $user = $this->accounts()->createUser(self::email(), null, self::actor());
        $this->browser->request('POST', '/dashboard/login', ['email' => $user->getEmail(), '_csrf_token' => 'forged']);
        self::assertStringEndsWith('/dashboard/login', (string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM auth_login_tokens WHERE user_id = ?', [$user->getId()->toRfc4122()]));

        $client = $this->newClient();
        $this->accounts()->setMembership($this->reload($user), $this->reload($client), ClientMembershipRole::Viewer, self::actor());
        $target = '/dashboard/c/'.$client->getId()->toRfc4122().'/usage';
        self::assertStringEndsWith('/dashboard/login', (string) $this->page($target)->headers->get('Location'));
        $path = $this->requestLink($user->getEmail());
        $this->browser->request('GET', (string) $path);
        self::assertStringEndsWith($target, (string) $this->browser->getResponse()->headers->get('Location'), 'back to where they were going');
    }

    public function testThereAreNoPasswords(): void
    {
        self::assertSame([], Db::owner()->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'users' AND column_name IN ('password_hash', 'global_role')"));
        $this->browser->request('POST', '/dashboard/login', ['email' => 'x@example.test', 'password' => 'anything at all']);
        self::assertStringEndsWith('/dashboard/login', (string) $this->browser->getResponse()->headers->get('Location'), 'a password form post signs nobody in');
        self::assertStringEndsWith('/dashboard/login', (string) $this->page('/dashboard')->headers->get('Location'));
    }

    private function adminUser(): \App\Entity\User
    {
        $user = $this->accounts()->createUser(self::email('admin'), null, self::actor());
        $this->container()->get(AccessControl::class)->grantRoleAsSystem($user, 'ADMIN', self::actor());

        return $user;
    }
}
