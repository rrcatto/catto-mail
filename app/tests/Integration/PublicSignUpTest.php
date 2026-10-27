<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Client\PublicSignUp;
use App\Enum\ClientMembershipRole;
use App\Security\LoginLinkService;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public sign-up (owner decision 2026-10-11): the page exists only with
 * APP_PUBLIC_ONBOARDING_ENABLED=true; a verified link opens an active client in the starter tier
 * with its client admin and the policy acceptance; addresses that already have an account, form
 * robots and floods are refused without revealing anything; the operator sees and is told.
 * Every request comes from this test's own client address (the rate limits count per address).
 */
final class PublicSignUpTest extends DashboardTestCase
{
    private string $from;

    protected function setUp(): void
    {
        parent::setUp();
        $this->from = '10.88.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private static function email(): string
    {
        return 'founder-'.bin2hex(random_bytes(4)).'@signup.example.org';
    }

    /** @param array<string, string> $env */
    private function open(callable $fn, array $env = []): mixed
    {
        return $this->withEnv(['APP_PUBLIC_ONBOARDING_ENABLED' => 'true'] + $env, $fn);
    }

    private function request(string $method, string $uri, array $fields = []): Response
    {
        $this->browser->request($method, $uri, $fields, server: ['REMOTE_ADDR' => $this->from]);

        return $this->browser->getResponse();
    }

    /** Fills in the form as a person would (its own CSRF token, the honeypot left empty). */
    private function submitForm(string $organisation, string $email, array $override = []): Response
    {
        $form = $this->browser->request('GET', '/dashboard/sign-up', server: ['REMOTE_ADDR' => $this->from]);
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());

        return $this->request('POST', '/dashboard/sign-up', $override + ['organisation' => $organisation, 'email' => $email, 'website' => '',
            '_csrf_token' => $form->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    /** The verification path of the last confirmation email to $email, or null. */
    private function emailedLink(string $email): ?string
    {
        foreach (array_reverse(self::getMailerMessages()) as $m) {
            if ($m instanceof TemplatedEmail && $m->getTo()[0]->getAddress() === $email && 'Confirm your Catto Mail sign-up' === $m->getSubject()) {
                self::assertSame(1, preg_match('#https://smarthost\.localhost(/dashboard/sign-up/verify\?token=[A-Za-z0-9_-]{43})#', (string) $m->getTextBody(), $x));

                return $x[1];
            }
        }

        return null;
    }

    private function confirm(string $link, bool $acceptPolicy = true): Response
    {
        $page = $this->browser->request('GET', $link, server: ['REMOTE_ADDR' => $this->from]);
        $form = $page->filter('form[action="/dashboard/sign-up/verify"]');
        self::assertSame(1, $form->count(), (string) $this->browser->getResponse()->getContent());

        return $this->request('POST', '/dashboard/sign-up/verify', ['token' => $form->filter('input[name="token"]')->attr('value'),
            '_csrf_token' => $form->filter('input[name="_csrf_token"]')->attr('value')] + ($acceptPolicy ? ['accept_policy' => '1'] : []));
    }

    private static function rows(string $sql, array $params = []): int
    {
        return (int) Db::owner()->fetchOne($sql, $params);
    }

    public function testDisabledByDefaultThereIsNoSignUp(): void
    {
        foreach ([['GET', '/dashboard/sign-up'], ['POST', '/dashboard/sign-up'], ['GET', '/dashboard/sign-up/sent'],
            ['GET', '/dashboard/sign-up/verify?token='.str_repeat('a', 43)], ['POST', '/dashboard/sign-up/verify']] as [$method, $uri]) {
            self::assertSame(404, $this->request($method, $uri, ['organisation' => 'X Ltd', 'email' => self::email()])->getStatusCode(), "$method $uri");
        }
        $front = self::text($this->page('/'));
        self::assertStringContainsString('There is no public sign-up: accounts are opened by invitation.', $front);
        self::assertStringNotContainsString('/dashboard/sign-up', (string) $this->browser->getResponse()->getContent());
        self::assertStringNotContainsString('/dashboard/sign-up', (string) $this->page('/dashboard/login')->getContent());
        self::assertFalse($this->container()->get(PublicSignUp::class)->enabled());
    }

    public function testASignUpOpensAnActiveStarterClientWithItsAdmin(): void
    {
        $this->open(function (): void {
            $email = self::email();
            $front = $this->page('/');
            self::assertStringContainsString('href="/dashboard/sign-up"', (string) $front->getContent(), 'the front page offers it');
            self::assertStringContainsString('href="/dashboard/sign-up"', (string) $this->page('/dashboard/login')->getContent());

            $r = $this->submitForm('  Example   Bakery  ', $email);
            self::assertSame(302, $r->getStatusCode());
            self::assertStringEndsWith('/dashboard/sign-up/sent', (string) $r->headers->get('Location'));
            $link = $this->emailedLink($email);
            self::assertNotNull($link, 'a confirmation link was emailed');
            // Nothing exists but the link (its hash, the address and the organisation).
            self::assertSame(0, self::rows('SELECT count(*) FROM users WHERE lower(email) = lower(?)', [$email]));
            self::assertSame(0, self::rows('SELECT count(*) FROM clients WHERE contact_email = ?', [$email]));
            self::assertSame(['sign_up', 'Example Bakery', null], array_values(Db::owner()->fetchAssociative(
                'SELECT purpose, organisation_name, used_at FROM auth_login_tokens WHERE email = ?', [$email])));
            self::assertStringNotContainsString(substr($link, -43), (string) Db::owner()->fetchOne('SELECT token_hash FROM auth_login_tokens WHERE email = ?', [$email]));

            // The link's page shows what will be created and changes nothing (GET).
            $page = $this->browser->request('GET', $link, server: ['REMOTE_ADDR' => $this->from]);
            self::assertSame(200, $this->browser->getResponse()->getStatusCode());
            self::assertStringContainsString('Example Bakery', $page->text());
            self::assertStringContainsString('aup-test-1', $page->text(), 'the policy version in force is to be accepted');
            self::assertSame(1, $page->filter('input[name="accept_policy"][required]')->count());
            self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
            self::assertNull(Db::owner()->fetchOne('SELECT used_at FROM auth_login_tokens WHERE email = ?', [$email]));
            self::assertSame(204, $this->request('HEAD', $link)->getStatusCode(), 'mail scanners get nothing');

            $r = $this->confirm($link);
            self::assertSame(302, $r->getStatusCode(), (string) $r->getContent());
            $client = Db::owner()->fetchAssociative('SELECT id::text AS id, company_name, status, origin, plan, approved_at FROM clients WHERE contact_email = ?', [$email]);
            self::assertSame(['Example Bakery', 'active', 'public_application', 'starter'], [$client['company_name'], $client['status'], $client['origin'], $client['plan']]);
            self::assertNotNull($client['approved_at']);
            self::assertStringEndsWith('/dashboard/c/'.$client['id'], (string) $r->headers->get('Location'));
            self::assertSame('admin', Db::owner()->fetchOne(
                'SELECT m.role FROM client_memberships m JOIN users u ON u.id = m.user_id WHERE m.client_id = ? AND u.email = ?', [$client['id'], $email]));
            self::assertSame(['aup-test-1', 'client_dashboard'], array_values(Db::owner()->fetchAssociative(
                'SELECT policy_version, source FROM client_policy_acceptances WHERE client_id = ?', [$client['id']])));
            $limits = Db::owner()->fetchAssociative('SELECT send_recipients_per_day, send_recipients_per_month, validation_addresses_per_day, max_api_keys,
                max_webhook_endpoints, max_sending_domains FROM client_limits WHERE client_id = ?', [$client['id']]);
            self::assertSame([200, 3000, 1000, 2, 2, 2], array_map('intval', array_values($limits)), 'the starter tier');
            self::assertSame(['system', 'public-sign-up'], array_values(Db::owner()->fetchAssociative(
                "SELECT actor_type, actor_id FROM audit_log WHERE action = 'client.approved' AND target_id = ?", [$client['id']])));
            self::assertNotNull(Db::owner()->fetchOne('SELECT used_at FROM auth_login_tokens WHERE email = ?', [$email]));

            // The operator is told.
            $mail = array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => str_starts_with((string) $m->getSubject(), 'New sign-up:')));
            self::assertCount(1, $mail);
            self::assertSame('admin@smarthost-dev.test', $mail[0]->getTo()[0]->getAddress());
            self::assertStringContainsString('/dashboard/operator/clients/'.$client['id'], (string) $mail[0]->getTextBody());

            // Signed in, on the overview with the next steps.
            $overview = $this->crawler('/dashboard/c/'.$client['id']);
            self::assertStringContainsString('Welcome to Catto Mail', $overview->filter('.flash')->text());
            $steps = $overview->filter('.guide-card');
            self::assertStringContainsString('1 of 4 done', $steps->text(), 'the policy was accepted on the way');
            self::assertSame('Make your sending domain ready', $steps->filter('.checklist li.current .checklist-title')->text());
            foreach (['sending-domains/new', 'api-keys', 'webhooks'] as $page) {
                self::assertSame(1, $steps->filter('a[href="/dashboard/c/'.$client['id'].'/'.$page.'"]')->count(), $page);
            }

            // The link worked once.
            $again = $this->browser->request('GET', $link, server: ['REMOTE_ADDR' => $this->from]);
            self::assertStringContainsString('not valid any more', $again->text());
            self::assertSame(0, $again->filter('form[action="/dashboard/sign-up/verify"]')->count());
        });
    }

    public function testThePolicyInForceMustBeAccepted(): void
    {
        $this->open(function (): void {
            $email = self::email();
            $this->submitForm('Policy Refuser Ltd', $email);
            $link = $this->emailedLink($email);
            $r = $this->confirm($link, false);
            self::assertSame(422, $r->getStatusCode());
            self::assertStringContainsString('Accept the service policy (version aup-test-1)', self::text($r));
            self::assertSame(0, self::rows('SELECT count(*) FROM clients WHERE contact_email = ?', [$email]), 'nothing created');
            self::assertNull(Db::owner()->fetchOne('SELECT used_at FROM auth_login_tokens WHERE email = ?', [$email]), 'the link still works');
            self::assertSame(302, $this->confirm($link)->getStatusCode());
            self::assertSame('active', Db::owner()->fetchOne('SELECT status FROM clients WHERE contact_email = ?', [$email]));
        });
        // Without a policy version in force there is nothing to accept.
        $this->open(function (): void {
            $email = self::email();
            $this->submitForm('No Policy Ltd', $email);
            $link = $this->emailedLink($email);
            self::assertSame(0, $this->browser->request('GET', $link, server: ['REMOTE_ADDR' => $this->from])->filter('input[name="accept_policy"]')->count());
            self::assertSame(302, $this->confirm($link, false)->getStatusCode());
            $id = (string) Db::owner()->fetchOne('SELECT id::text FROM clients WHERE contact_email = ?', [$email]);
            self::assertSame('active', Db::owner()->fetchOne('SELECT status FROM clients WHERE id = ?', [$id]));
            self::assertSame(0, self::rows('SELECT count(*) FROM client_policy_acceptances WHERE client_id = ?', [$id]));
        }, ['APP_ACCEPTABLE_USE_POLICY_VERSION' => '']);
    }

    public function testAnAddressThatAlreadyHasAnAccountGetsNoSignUp(): void
    {
        $user = $this->newUser(false, $this->newClient());
        $contactOnly = $this->newClient();
        $contact = (string) Db::owner()->fetchOne('SELECT contact_email FROM clients WHERE id = ?', [$contactOnly->getId()->toRfc4122()]);
        $this->open(function () use ($user, $contact): void {
            // The page says the same as for a new address; the user gets an ordinary sign-in link instead.
            $r = $this->submitForm('Copycat Ltd', strtoupper($user->getEmail()));
            self::assertStringEndsWith('/dashboard/sign-up/sent', (string) $r->headers->get('Location'));
            self::assertNull($this->emailedLink(strtoupper($user->getEmail())));
            self::assertSame(['Your sign-in link for Catto Mail'], array_map(static fn ($m): string => (string) $m->getSubject(), self::getMailerMessages()));
            $r = $this->submitForm('Copycat Ltd', $contact);
            self::assertStringEndsWith('/dashboard/sign-up/sent', (string) $r->headers->get('Location'));
            self::assertSame([], self::getMailerMessages(), 'a client contact without a user gets nothing');
            self::assertSame(0, self::rows("SELECT count(*) FROM auth_login_tokens WHERE purpose = 'sign_up' AND lower(email) IN (lower(?), lower(?))", [$user->getEmail(), $contact]));

            // An address taken between the link and its confirmation.
            $email = self::email();
            $this->submitForm('Late Ltd', $email);
            $link = $this->emailedLink($email);
            $this->accounts()->createUser($email, null, self::actor());
            $page = $this->browser->request('GET', $link, server: ['REMOTE_ADDR' => $this->from]);
            self::assertStringContainsString('already belongs to an account', $page->text());
            self::assertSame(0, $page->filter('form[action="/dashboard/sign-up/verify"]')->count());
            $this->request('POST', '/dashboard/sign-up/verify', ['token' => substr($link, -43), '_csrf_token' => 'x']);
            self::assertSame(0, self::rows('SELECT count(*) FROM clients WHERE contact_email = ?', [$email]));
        });
    }

    public function testTheHoneypotAndCsrfStopRobots(): void
    {
        $this->open(function (): void {
            $email = self::email();
            $r = $this->submitForm('Robot Inc', $email, ['website' => 'https://spam.example']);
            self::assertStringEndsWith('/dashboard/sign-up/sent', (string) $r->headers->get('Location'), 'answered like a success');
            self::assertSame([], self::getMailerMessages());
            self::assertSame(0, self::rows('SELECT count(*) FROM auth_login_tokens WHERE email = ?', [$email]));

            $r = $this->request('POST', '/dashboard/sign-up', ['organisation' => 'Robot Inc', 'email' => $email, '_csrf_token' => 'forged']);
            self::assertStringEndsWith('/dashboard/sign-up', (string) $r->headers->get('Location'));
            self::assertSame(0, self::rows('SELECT count(*) FROM auth_login_tokens WHERE email = ?', [$email]));

            // Invalid input is said plainly (it reveals nothing about any account).
            $r = $this->submitForm('X', 'not-an-address');
            self::assertSame(422, $r->getStatusCode());
            self::assertStringContainsString('organisation', self::text($r));
        });
    }

    public function testFloodsAreLimitedPerClientAddressAndPerEmailAddress(): void
    {
        $this->open(function (): void {
            // At most three links an hour per address; the page never says.
            $email = self::email();
            for ($i = 0; $i < LoginLinkService::SIGN_UP_MAX_PER_EMAIL + 1; ++$i) {
                self::assertSame(302, $this->submitForm('Flood Ltd', $email)->getStatusCode());
            }
            self::assertSame(LoginLinkService::SIGN_UP_MAX_PER_EMAIL, self::rows("SELECT count(*) FROM auth_login_tokens WHERE purpose = 'sign_up' AND email = ?", [$email]));

            // At most ten submissions an hour per client address, whatever they contain.
            for ($i = LoginLinkService::SIGN_UP_MAX_PER_EMAIL + 1; $i < 10; ++$i) {
                self::assertSame(302, $this->submitForm('Flood Ltd', self::email(), ['website' => 'x'])->getStatusCode(), "submission $i");
            }
            $r = $this->submitForm('Flood Ltd', self::email());
            self::assertSame(429, $r->getStatusCode());
            self::assertStringContainsString('Too many attempts', self::text($r));

            // Verification: five confirmations an hour per address.
            $this->from = '10.89.'.random_int(0, 255).'.'.random_int(1, 254);
            $other = self::email();
            $this->submitForm('Patient Ltd', $other);
            $link = $this->emailedLink($other);
            for ($i = 0; $i < 5; ++$i) {
                self::assertSame(422, $this->confirm($link, false)->getStatusCode(), "confirmation $i (policy not accepted)");
            }
            self::assertSame(429, $this->confirm($link)->getStatusCode(), 'the sixth confirmation for the address');
            self::assertSame(0, self::rows('SELECT count(*) FROM clients WHERE contact_email = ?', [$other]));

            // ... and twenty verification requests an hour per client address.
            $this->from = '10.90.'.random_int(0, 255).'.'.random_int(1, 254);
            for ($i = 0; $i < 20; ++$i) {
                self::assertSame(200, $this->request('GET', '/dashboard/sign-up/verify?token='.str_repeat('b', 43))->getStatusCode(), "verification $i");
            }
            self::assertSame(429, $this->request('GET', '/dashboard/sign-up/verify?token='.str_repeat('b', 43))->getStatusCode());
        });
    }

    public function testStarterLimitsAreTheStarterPlanCappedByTheCeilings(): void
    {
        $before = Db::owner()->fetchAssociative("SELECT send_recipients_per_day, max_api_keys FROM plans WHERE id = 'starter'");
        // A value above an installation ceiling (a plan saved under a higher ceiling) is capped when a client gets it.
        Db::owner()->executeStatement("UPDATE plans SET send_recipients_per_day = 50, max_api_keys = 50 WHERE id = 'starter'");
        try {
            $this->open(function (): void {
                $limits = $this->container()->get(PublicSignUp::class)->starterLimits();
                self::assertEquals(['send_recipients_per_day' => 50, 'send_recipients_per_month' => 3000, 'validation_addresses_per_day' => 1000,
                    'max_api_keys' => 10, 'max_webhook_endpoints' => 2, 'max_sending_domains' => 2], array_filter($limits, static fn (?int $v): bool => null !== $v),
                    'API keys capped at APP_CLIENT_API_KEY_LIMIT (10)');
                $email = self::email();
                $this->submitForm('Capped Ltd', $email);
                $this->confirm($this->emailedLink($email));
                self::assertSame(['50', '10', 'starter'], array_map('strval', array_values(Db::owner()->fetchAssociative(
                    'SELECT l.send_recipients_per_day, l.max_api_keys, c.plan FROM client_limits l JOIN clients c ON c.id = l.client_id WHERE c.contact_email = ?', [$email]))));
            });
        } finally {
            Db::owner()->executeStatement("UPDATE plans SET send_recipients_per_day = ?, max_api_keys = ? WHERE id = 'starter'", [$before['send_recipients_per_day'], $before['max_api_keys']]);
        }
        $catalog = $this->container()->get(\App\System\SettingCatalog::class);
        foreach (['APP_STARTER_SEND_RECIPIENTS_PER_DAY', 'APP_STARTER_API_KEY_LIMIT'] as $name) {
            self::assertFalse($catalog->has($name), "$name is gone: the Starter plan replaces it (Clients › Plans)");
        }
    }

    public function testTheOperatorSeesNewSignUps(): void
    {
        $email = self::email();
        $this->open(function () use ($email): void {
            $this->submitForm('Visible Sign-up Ltd', $email);
            $this->confirm($this->emailedLink($email));
        });
        $this->signIn($this->newUser(true, null, ClientMembershipRole::Viewer));
        $list = $this->crawler('/dashboard/operator/clients/sign-ups');
        $row = $list->filter('tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'Visible Sign-up Ltd'));
        self::assertSame(1, $row->count());
        self::assertStringContainsString('sign-up', $row->filter('.tag')->text());
        self::assertSame(0, $this->crawler('/dashboard/operator/clients/invited')->filter('tbody tr')->reduce(
            static fn ($tr): bool => str_contains($tr->text(), 'Visible Sign-up Ltd'))->count());
        $overview = $this->crawler('/dashboard/operator');
        self::assertMatchesRegularExpression('/new public sign-up/i', $overview->filter('#attention')->text());
    }
}
