<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Client\AccountAdministration;
use App\Domain\DomainRuleViolation;
use App\Enum\ClientMembershipRole;
use App\Security\ClientVoter;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Dashboard user foundation (D-12): users, memberships, operator role, disabled users, login. */
final class DashboardUserTest extends ApiTestCase
{
    private function accounts(): AccountAdministration
    {
        return $this->container()->get(AccountAdministration::class);
    }

    private static function email(string $local = 'User'): string
    {
        return $local.'.'.bin2hex(random_bytes(4)).'@Example.test';
    }

    private function login(string $email, string $password): \Symfony\Component\HttpFoundation\Response
    {
        $crawler = $this->browser->request('GET', '/dashboard/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->browser->request('POST', '/dashboard/login', ['email' => $email, 'password' => $password, '_csrf_token' => $token],
            server: ['REMOTE_ADDR' => '10.9.'.random_int(0, 255).'.'.random_int(1, 254)]);

        return $this->browser->getResponse();
    }

    public function testLoginEmailIsUniqueCaseInsensitively(): void
    {
        $email = self::email();
        $user = $this->accounts()->createUser($email, null, null, false, self::actor());
        self::assertSame($email, $user->getEmail(), 'stored as entered');
        $this->expectException(DomainRuleViolation::class);
        $this->accounts()->createUser(strtolower($email), null, null, false, self::actor());
    }

    public function testDatabaseEnforcesCaseInsensitiveUniquenessToo(): void
    {
        $email = self::email();
        $this->accounts()->createUser($email, null, null, false, self::actor());
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        Db::app()->insert('users', ['id' => \Symfony\Component\Uid\Uuid::v7()->toRfc4122(), 'email' => strtoupper($email)]);
    }

    public function testMembershipsAndVoter(): void
    {
        $client = $this->newClient();
        $other = $this->newClient();
        $viewer = $this->accounts()->createUser(self::email('viewer'), null, null, false, self::actor());
        $member = $this->accounts()->createUser(self::email('member'), null, null, false, self::actor());
        $admin = $this->accounts()->createUser(self::email('admin'), null, null, false, self::actor());
        $operator = $this->accounts()->createUser(self::email('op'), null, null, true, self::actor());
        $this->accounts()->setMembership($viewer, $this->reload($client), ClientMembershipRole::Viewer, self::actor());
        $this->accounts()->setMembership($member, $this->reload($client), ClientMembershipRole::Member, self::actor());
        $m = $this->accounts()->setMembership($admin, $this->reload($client), ClientMembershipRole::Viewer, self::actor());
        $this->accounts()->setMembership($admin, $this->reload($client), ClientMembershipRole::Admin, self::actor());
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM client_memberships WHERE user_id = ?', [$admin->getId()->toRfc4122()]));
        self::assertSame(['client_membership.created', 'client_membership.role_changed'],
            Db::owner()->fetchFirstColumn('SELECT action FROM audit_log WHERE target_id = ? ORDER BY occurred_at', [$m->getId()->toRfc4122()]));

        $voter = new ClientVoter($this->container()->get('doctrine')->getManager());
        $can = fn ($user, string $attr, $c) => 1 === $voter->vote(new UsernamePasswordToken($this->reload($user), 'dashboard', $user->getRoles()), $this->reload($c), [$attr]);
        self::assertTrue($can($viewer, ClientVoter::VIEW, $client));
        self::assertFalse($can($viewer, ClientVoter::OPERATE, $client));
        self::assertTrue($can($member, ClientVoter::OPERATE, $client));
        self::assertFalse($can($member, ClientVoter::ADMIN, $client));
        self::assertTrue($can($admin, ClientVoter::ADMIN, $client));
        self::assertFalse($can($admin, ClientVoter::VIEW, $other), 'membership is per client');
        self::assertTrue($can($operator, ClientVoter::ADMIN, $other), 'operators act through the global role');

        $this->accounts()->setDisabled($this->reload($admin), true, self::actor());
        self::assertFalse($can($admin, ClientVoter::VIEW, $client), 'disabled users get nothing');
    }

    public function testOperatorRoleConstraints(): void
    {
        $user = $this->accounts()->createUser(self::email(), null, null, false, self::actor());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        $this->accounts()->setOperator($user, true, self::actor());
        self::assertSame(['ROLE_USER', 'ROLE_OPERATOR'], $user->getRoles());
        self::assertSame('operator', Db::owner()->fetchOne('SELECT global_role FROM users WHERE id = ?', [$user->getId()->toRfc4122()]));
        $this->accounts()->setOperator($user, false, self::actor());
        self::assertNull(Db::owner()->fetchOne('SELECT global_role FROM users WHERE id = ?', [$user->getId()->toRfc4122()]));
        self::assertSame(['user.created', 'user.global_role_changed', 'user.global_role_changed'],
            Db::owner()->fetchFirstColumn('SELECT action FROM audit_log WHERE target_id = ? ORDER BY occurred_at', [$user->getId()->toRfc4122()]));
    }

    public function testDashboardLoginLifecycle(): void
    {
        $email = self::email();
        $password = 'correct horse battery staple';
        $user = $this->accounts()->createUser($email, 'Ann', $password, false, self::actor());
        $hash = Db::owner()->fetchOne('SELECT password_hash FROM users WHERE id = ?', [$user->getId()->toRfc4122()]);
        self::assertStringNotContainsString($password, (string) $hash);

        self::assertStringEndsWith('/dashboard/login', (string) $this->login(strtoupper($email), 'wrong password!!')->headers->get('Location'));
        $r = $this->login(strtoupper($email), $password);
        self::assertStringEndsWith('/dashboard', (string) $r->headers->get('Location'), 'login identifier is case-insensitive');
        $this->browser->request('GET', '/dashboard');
        self::assertStringContainsString('Signed in as', (string) $this->browser->getResponse()->getContent());
        self::assertNotNull(Db::owner()->fetchOne('SELECT last_login_at FROM users WHERE id = ?', [$user->getId()->toRfc4122()]));

        // A dashboard session is not an API credential.
        $this->browser->request('GET', '/v1/send-jobs/01999999-0000-7000-8000-000000000000');
        self::assertSame(401, $this->browser->getResponse()->getStatusCode());
    }

    public function testDisabledAndInvitedUsersCannotLogIn(): void
    {
        $email = self::email();
        $user = $this->accounts()->createUser($email, null, 'a long enough password', false, self::actor());
        $this->accounts()->setDisabled($user, true, self::actor());
        self::assertStringEndsWith('/dashboard/login', (string) $this->login($email, 'a long enough password')->headers->get('Location'));
        self::assertNotNull(Db::owner()->fetchOne('SELECT disabled_at FROM users WHERE id = ?', [$user->getId()->toRfc4122()]));

        $invited = self::email();
        $this->accounts()->createUser($invited, null, null, false, self::actor());
        self::assertStringEndsWith('/dashboard/login', (string) $this->login($invited, 'anything at all here')->headers->get('Location'));
    }

    public function testApiKeysAreNeverAcceptedAsPasswords(): void
    {
        [$client, $raw] = $this->newApiClient();
        $email = self::email();
        $this->accounts()->createUser($email, null, 'another long password', false, self::actor());
        self::assertStringEndsWith('/dashboard/login', (string) $this->login($email, $raw)->headers->get('Location'));
        self::assertStringEndsWith('/dashboard/login', (string) $this->login('api-key', $raw)->headers->get('Location'));
    }

    public function testLoginRequiresCsrfToken(): void
    {
        $email = self::email();
        $this->accounts()->createUser($email, null, 'a long enough password', false, self::actor());
        $this->browser->request('POST', '/dashboard/login', ['email' => $email, 'password' => 'a long enough password', '_csrf_token' => 'forged']);
        self::assertStringEndsWith('/dashboard/login', (string) $this->browser->getResponse()->headers->get('Location'));
    }

    public function testWeakPasswordsAreRefused(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->accounts()->createUser(self::email(), null, 'short', false, self::actor());
    }
}
