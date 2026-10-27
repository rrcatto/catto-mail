<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Client;
use App\Enum\ClientMembershipRole;
use App\Enum\ClientStatus;
use App\System\ApplicationDiagnostics;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

/**
 * How accounts open (owner decisions 2026-10-27, specification 2.13): New client on its own page
 * (the manager made the admin and emailed; active at once, or opening when the manager accepts
 * the policy), plans defined once and followed by the clients on them, invitations while the
 * public sign-up stays closed, the operator's setup page split into "needs you" and "checked
 * automatically", the self-test client labelled, and the client views at plain addresses. The
 * test environment has the policy version aup-test-1 in force.
 */
final class AccountSetupTest extends DashboardTestCase
{
    private const BASE = '/dashboard/operator/clients';

    private string $from;

    protected function setUp(): void
    {
        parent::setUp();
        $this->from = '10.66.'.random_int(0, 255).'.'.random_int(1, 254);
        $this->browser->setServerParameter('REMOTE_ADDR', $this->from);
    }

    private static function email(string $who = 'manager'): string
    {
        return $who.'-'.bin2hex(random_bytes(4)).'@accounts.example.org';
    }

    private static function named(string $prefix): string
    {
        return $prefix.' '.bin2hex(random_bytes(3));
    }

    /** Fills in a form found on $page by its action, with its own hidden fields and CSRF token. */
    private function post(string $page, string $action, array $fields): Response
    {
        $form = $this->crawler($page)->filter(\sprintf('form[action="%s"]', $action));
        self::assertSame(1, $form->count(), "form $action on $page");
        $hidden = [];
        foreach ($form->filter('input[type="hidden"]') as $input) {
            $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $this->browser->request('POST', $action, $fields + $hidden);

        return $this->browser->getResponse();
    }

    /** @return list<Email> the mail sent to $address */
    private static function mailTo(string $address): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email && $m->getTo()[0]->getAddress() === $address));
    }

    private static function client(string $name): array
    {
        $row = Db::owner()->fetchAssociative('SELECT id::text AS id, status, plan, origin, policy_acceptance_required, opens_on_policy_acceptance, contact_email FROM clients WHERE company_name = ?', [$name]);
        self::assertIsArray($row, "client $name");

        return $row;
    }

    private static function limits(string $clientId): array
    {
        return array_map(static fn ($v): ?int => null === $v ? null : (int) $v,
            Db::owner()->fetchAssociative('SELECT send_recipients_per_day, max_api_keys FROM client_limits WHERE client_id = ?', [$clientId]) ?: []);
    }

    public function testNewClientOpensAnAccountForItsManager(): void
    {
        $this->signIn($this->newUser(true));
        $menu = $this->crawler(self::BASE);
        self::assertContains('New client', $menu->filter('.menu-panel .menu-label')->each(static fn (Crawler $n): string => $n->text()), 'in the Clients menu');
        self::assertSame(0, $menu->filter('nav.crumbs')->count(), 'the title says where it is, once');

        $form = $this->crawler(self::BASE.'/new')->filter('form[action="'.self::BASE.'/new"]');
        $names = array_values(array_unique($form->filter('input:not([type="hidden"]), textarea, select')->each(static fn (Crawler $i): string => (string) $i->attr('name'))));
        self::assertSame(['company_name', 'manager_email', 'plan', 'policy', 'note'], $names, 'only what is used');
        self::assertSame(['standard', 'starter'], array_values(array_intersect(['standard', 'starter'], $form->filter('input[name="plan"]')->each(static fn (Crawler $i): string => (string) $i->attr('value')))));

        // Exempt from the policy: active at once, on the plan's limits; the manager is its admin and is emailed.
        $name = self::named('Exempt Co');
        $manager = self::email();
        $r = $this->post(self::BASE.'/new', self::BASE.'/new', ['company_name' => $name, 'manager_email' => $manager, 'plan' => 'starter', 'policy' => 'exempt', 'note' => 'our own lists']);
        $client = self::client($name);
        self::assertSame(self::BASE.'/'.$client['id'], $r->headers->get('Location'));
        self::assertSame(['active', 'starter', 'operator', false, false, $manager],
            [$client['status'], $client['plan'], $client['origin'], $client['policy_acceptance_required'], $client['opens_on_policy_acceptance'], $client['contact_email']]);
        self::assertSame(['send_recipients_per_day' => 200, 'max_api_keys' => 2], self::limits($client['id']));
        self::assertSame('admin', Db::owner()->fetchOne('SELECT m.role FROM client_memberships m JOIN users u ON u.id = m.user_id WHERE m.client_id = ? AND lower(u.email) = lower(?)', [$client['id'], $manager]));
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM client_notes WHERE client_id = ?', [$client['id']]));
        $welcome = self::mailTo($manager);
        self::assertCount(1, $welcome);
        self::assertSame("Your Catto Mail account for $name", $welcome[0]->getSubject());
        self::assertSame(1, preg_match('#https://smarthost\.localhost(/dashboard/login/verify\?token=[A-Za-z0-9_-]{43})#', (string) $welcome[0]->getTextBody(), $m));
        $page = $this->crawler(self::BASE.'/'.$client['id']);
        self::assertStringContainsString("$manager was emailed a link to sign in", $page->filter('.flash')->text());
        self::assertStringContainsString($manager, $page->filter('section[aria-labelledby="acc-h"]')->text(), 'who manages it');
        self::assertSame(0, $page->filter('input[name="billing_contact_email"], input[name="abuse_contact_email"], input[name="contact_email"]')->count());

        // The manager's link signs them in to their account.
        $this->browser->getCookieJar()->clear();
        $this->browser->request('GET', $m[1]);
        self::assertSame('/dashboard/c/'.$client['id'], $this->browser->getResponse()->headers->get('Location'));

        // Refused input keeps what was typed.
        $this->browser->getCookieJar()->clear();
        $this->signIn($this->newUser(true));
        $r = $this->post(self::BASE.'/new', self::BASE.'/new', ['company_name' => 'Typo Co', 'manager_email' => 'not an address', 'plan' => 'standard', 'policy' => 'exempt', 'note' => '']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Give the email address of the person who manages the account', self::text($r));
        self::assertStringContainsString('value="Typo Co"', (string) $r->getContent());
        $r = $this->post(self::BASE.'/new', self::BASE.'/new', ['company_name' => 'Typo Co', 'manager_email' => self::email(), 'plan' => 'gold-nope', 'policy' => 'exempt', 'note' => '']);
        self::assertStringContainsString('Choose one of the plans', self::text($r));
        self::assertFalse(Db::owner()->fetchOne("SELECT 1 FROM clients WHERE company_name = 'Typo Co'"));
    }

    public function testAnAccountThatMustAcceptThePolicyOpensWhenItsManagerAccepts(): void
    {
        $this->signIn($this->newUser(true));
        $name = self::named('Outside Co');
        $manager = self::email();
        $this->post(self::BASE.'/new', self::BASE.'/new', ['company_name' => $name, 'manager_email' => $manager, 'plan' => 'standard', 'policy' => 'must_accept', 'note' => '']);
        $client = self::client($name);
        self::assertSame(['pending_approval', true, true], [$client['status'], $client['policy_acceptance_required'], $client['opens_on_policy_acceptance']]);
        $page = $this->crawler(self::BASE.'/'.$client['id']);
        self::assertStringContainsString('as soon as its manager accepts the service policy', $page->filter('.flash')->text());
        self::assertStringContainsString('It opens by itself when its manager accepts the service policy', $page->text());
        self::assertStringContainsString('opens when its manager accepts the policy', $this->crawler(self::BASE.'/waiting')->filter('tbody')->text());

        $this->browser->getCookieJar()->clear();
        $this->signIn($this->reload($this->container()->get(\App\Security\DashboardUserProvider::class)->findByEmail($manager)));
        $overview = $this->crawler('/dashboard/c/'.$client['id']);
        self::assertSame('Accept the service policy', $overview->filter('.checklist li.current .checklist-title')->text());
        self::assertStringContainsString('Your account opens when you accept it', $overview->text());
        $this->post('/dashboard/c/'.$client['id'], '/dashboard/c/'.$client['id'].'/policy/accept', []);
        self::assertSame('active', self::client($name)['status']);
        self::assertStringContainsString('your account is open', $this->crawler('/dashboard/c/'.$client['id'])->filter('.flash')->text());
        self::assertSame(['system', 'policy-acceptance'], array_values(Db::owner()->fetchAssociative(
            "SELECT actor_type, actor_id FROM audit_log WHERE action = 'client.approved' AND target_id = ?", [$client['id']])));
    }

    public function testPlansAreDefinedOnceAndTheirClientsFollowThem(): void
    {
        $this->signIn($this->newUser(true));
        $plans = $this->crawler(self::BASE.'/plans');
        self::assertStringContainsString('Standard', $plans->text());
        self::assertStringContainsString('Starter', $plans->text());
        self::assertSame(2, $plans->filter('.tag')->reduce(static fn (Crawler $t): bool => 'built in' === $t->text())->count());

        $plan = self::named('Gold');
        $r = $this->post(self::BASE.'/plans/new', self::BASE.'/plans/new', ['name' => $plan, 'limits' => ['send_recipients_per_day' => '5,000', 'max_api_keys' => '4']]);
        self::assertSame(302, $r->getStatusCode(), self::text($r));
        $id = (string) Db::owner()->fetchOne('SELECT id FROM plans WHERE name = ?', [$plan]);
        self::assertMatchesRegularExpression('/^gold-[0-9a-f]{6}$/', $id);
        $r = $this->post(self::BASE.'/plans/new', self::BASE.'/plans/new', ['name' => strtoupper($plan), 'limits' => []]);
        self::assertStringContainsString('There is already a plan called', self::text($r));
        $r = $this->post(self::BASE.'/plans/new', self::BASE.'/plans/new', ['name' => self::named('Too much'), 'limits' => ['max_api_keys' => '11']]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('the most this installation allows', self::text($r));

        // Two clients on it; one is changed by hand and becomes custom.
        $a = $this->newClient(ClientStatus::Active, 'Plan A');
        $b = $this->newClient(ClientStatus::Active, 'Plan B');
        foreach ([$a, $b] as $c) {
            $page = self::BASE.'/'.$c->getId()->toRfc4122().'?tab=limits';
            $this->post($page, self::BASE.'/'.$c->getId()->toRfc4122().'/plan', ['plan' => $id]);
            self::assertSame(['send_recipients_per_day' => 5000, 'max_api_keys' => 4], self::limits($c->getId()->toRfc4122()));
        }
        $bPage = self::BASE.'/'.$b->getId()->toRfc4122().'?tab=limits';
        $this->post($bPage, self::BASE.'/'.$b->getId()->toRfc4122().'/limits', ['send_recipients_per_day' => '7000', 'max_api_keys' => '4', 'note' => 'agreed']);
        self::assertStringContainsString('Custom limits, changed by hand', $this->crawler($bPage)->text());
        self::assertStringContainsString("The limits of the $plan plan", $this->crawler(self::BASE.'/'.$a->getId()->toRfc4122().'?tab=limits')->text());

        // Changing the plan moves the client on it, not the custom one.
        $r = $this->post(self::BASE."/plans/$id", self::BASE."/plans/$id", ['name' => $plan, 'limits' => ['send_recipients_per_day' => '6000', 'max_api_keys' => '4']]);
        self::assertSame(302, $r->getStatusCode());
        self::assertStringContainsString('The limits of 1 client on it changed with it', $this->crawler(self::BASE.'/plans')->filter('.flash')->text());
        self::assertSame(6000, self::limits($a->getId()->toRfc4122())['send_recipients_per_day']);
        self::assertSame(7000, self::limits($b->getId()->toRfc4122())['send_recipients_per_day']);
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'plan.updated' AND target_id = ?", [$id]));

        // Built-in plans cannot be deleted; a plan in use is kept until its clients move.
        self::assertSame(0, $this->crawler(self::BASE.'/plans/standard')->filter('form[action$="/delete"]')->count());
        $this->post(self::BASE."/plans/$id", self::BASE."/plans/$id/delete", []);
        self::assertStringContainsString('is still the plan of a client', $this->crawler(self::BASE.'/plans')->filter('.flash')->text());
        foreach ([$a, $b] as $c) {
            $this->post(self::BASE.'/'.$c->getId()->toRfc4122().'?tab=limits', self::BASE.'/'.$c->getId()->toRfc4122().'/plan', ['plan' => 'standard']);
        }
        self::assertSame(['send_recipients_per_day' => null, 'max_api_keys' => null], self::limits($b->getId()->toRfc4122()), 'back on a plan: custom limits are gone');
        $this->post(self::BASE."/plans/$id", self::BASE."/plans/$id/delete", []);
        self::assertFalse(Db::owner()->fetchOne('SELECT 1 FROM plans WHERE id = ?', [$id]));
    }

    public function testAnInvitationOpensAnAccountWhileSignUpIsClosed(): void
    {
        $operator = $this->newUser(true);
        $this->signIn($operator);
        $note = self::named('For the bakery');
        $r = $this->post(self::BASE.'/invitations/new', self::BASE.'/invitations/new', ['note' => $note, 'plan' => 'starter', 'policy' => 'must_accept']);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $page = new Crawler((string) $r->getContent());
        $url = $page->filter('[data-controller="copy"] [data-copy-target="source"]')->text();
        self::assertMatchesRegularExpression('#^https://smarthost\.localhost/dashboard/invitation\?token=[A-Za-z0-9_-]{43}$#', $url, 'shown once, with a copy button');
        $token = substr($url, -43);
        $invitation = Db::owner()->fetchAssociative('SELECT id::text AS id, token_hash, plan, used_at FROM client_invitations WHERE note = ?', [$note]);
        self::assertSame([hash('sha256', $token), 'starter', null], [$invitation['token_hash'], $invitation['plan'], $invitation['used_at']], 'only the hash is kept');
        self::assertStringContainsString($note, $this->crawler(self::BASE.'/invitations')->text());

        // Whoever opens it names the organisation and confirms the address; nothing exists before that.
        $this->browser->getCookieJar()->clear();
        $form = $this->crawler("/dashboard/invitation?token=$token")->filter('form[action="/dashboard/invitation"]');
        self::assertSame(['organisation', 'email', 'website'], $form->filter('input:not([type="hidden"])')->each(static fn (Crawler $i): string => (string) $i->attr('name')));
        $email = self::email('baker');
        $organisation = self::named('Invited Bakery');
        $this->browser->request('POST', '/dashboard/invitation', ['token' => $token, 'organisation' => $organisation, 'email' => $email, 'website' => '',
            '_csrf_token' => $form->filter('input[name="_csrf_token"]')->attr('value')]);
        self::assertSame('/dashboard/invitation/sent', $this->browser->getResponse()->headers->get('Location'));
        self::assertFalse(Db::owner()->fetchOne('SELECT 1 FROM clients WHERE company_name = ?', [$organisation]));
        $mail = self::mailTo($email);
        self::assertCount(1, $mail);
        self::assertInstanceOf(TemplatedEmail::class, $mail[0]);
        self::assertSame(1, preg_match('#https://smarthost\.localhost(/dashboard/invitation/confirm\?token=[A-Za-z0-9_-]{43})#', (string) $mail[0]->getTextBody(), $m));

        $confirm = $this->crawler($m[1]);
        self::assertStringContainsString('Starter plan', $confirm->text());
        $fields = ['token' => $confirm->filter('input[name="token"]')->attr('value'), '_csrf_token' => $confirm->filter('input[name="_csrf_token"]')->attr('value')];
        $this->browser->request('POST', '/dashboard/invitation/confirm', $fields);
        self::assertSame(422, $this->browser->getResponse()->getStatusCode(), 'the invitation requires the policy');
        $this->browser->request('POST', '/dashboard/invitation/confirm', $fields + ['accept_policy' => '1']);
        $client = self::client($organisation);
        self::assertSame('/dashboard/c/'.$client['id'], $this->browser->getResponse()->headers->get('Location'), 'signed in, on the start page');
        self::assertSame(['active', 'starter', 'invitation'], [$client['status'], $client['plan'], $client['origin']]);
        self::assertSame(['send_recipients_per_day' => 200, 'max_api_keys' => 2], self::limits($client['id']));
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM client_policy_acceptances WHERE client_id = ?', [$client['id']]));
        self::assertSame($client['id'], Db::owner()->fetchOne('SELECT client_id::text FROM client_invitations WHERE id = ?', [$invitation['id']]));
        self::assertCount(1, array_filter(self::getMailerMessages(), static fn ($x): bool => "Invitation used: $organisation" === $x->getSubject()), 'the operator is told');
        self::assertStringContainsString('Get ready to send', $this->crawler('/dashboard/c/'.$client['id'])->text());

        // Used: the link is gone, and the account is in the operator's view of invited clients.
        $this->browser->getCookieJar()->clear();
        self::assertSame(410, $this->page("/dashboard/invitation?token=$token")->getStatusCode());
        $this->signIn($operator);
        self::assertStringContainsString($organisation, $this->crawler(self::BASE.'/invited')->filter('tbody')->text());
        self::assertStringContainsString('invitation', $this->crawler(self::BASE)->filter('tbody')->text());
    }

    public function testSomeoneSignedInOpensAnInvitedAccountAndAWithdrawnInvitationIsGone(): void
    {
        $this->signIn($this->newUser(true));
        $made = $this->post(self::BASE.'/invitations/new', self::BASE.'/invitations/new', ['note' => '', 'plan' => 'standard', 'policy' => 'exempt']);
        $token = substr((new Crawler((string) $made->getContent()))->filter('[data-copy-target="source"]')->text(), -43);
        $withdrawn = $this->post(self::BASE.'/invitations/new', self::BASE.'/invitations/new', ['note' => 'withdrawn', 'plan' => 'standard', 'policy' => 'exempt']);
        $gone = substr((new Crawler((string) $withdrawn->getContent()))->filter('[data-copy-target="source"]')->text(), -43);
        $id = (string) Db::owner()->fetchOne('SELECT id::text FROM client_invitations WHERE token_hash = ?', [hash('sha256', $gone)]);
        $this->post(self::BASE.'/invitations', self::BASE."/invitations/$id/revoke", []);
        self::assertStringContainsString('withdrawn', $this->crawler(self::BASE.'/invitations')->text());

        $this->browser->getCookieJar()->clear();
        self::assertSame(410, $this->page("/dashboard/invitation?token=$gone")->getStatusCode());
        $user = $this->newUser(false);
        $this->signIn($user);
        $form = $this->crawler("/dashboard/invitation?token=$token")->filter('form[action="/dashboard/invitation"]');
        self::assertSame(['organisation'], $form->filter('input:not([type="hidden"])')->each(static fn (Crawler $i): string => (string) $i->attr('name')), 'signed in: the organisation is enough');
        $organisation = self::named('Signed In Ltd');
        $this->browser->request('POST', '/dashboard/invitation', ['token' => $token, 'organisation' => $organisation,
            '_csrf_token' => $form->filter('input[name="_csrf_token"]')->attr('value')]);
        $client = self::client($organisation);
        self::assertSame('/dashboard/c/'.$client['id'], $this->browser->getResponse()->headers->get('Location'));
        self::assertSame(['active', 'invitation', false], [$client['status'], $client['origin'], $client['policy_acceptance_required']]);
        self::assertSame('admin', Db::owner()->fetchOne('SELECT role FROM client_memberships WHERE client_id = ? AND user_id = ?', [$client['id'], $user->getId()->toRfc4122()]));
    }

    public function testSystemSetupListsWhatNeedsTheAdministratorFirst(): void
    {
        $this->signIn($this->newUser(false, null, roleKey: 'ADMIN'));
        $page = $this->crawler('/dashboard/operator/setup');
        $needs = $page->filter('section[aria-labelledby="sg-you"] .setup-title')->each(static fn (Crawler $t): string => $t->text());
        self::assertSame(['DNS', 'Backups', 'Seed delivery test', 'Bounce test', 'Reboot test', 'Production readiness'], $needs);
        self::assertSame(1, $page->filter('section[aria-labelledby="sg-auto"]')->count());
        self::assertSame(0, $page->filter('section[aria-labelledby="sg-auto"] button[value="done"]')->count(), 'nothing to mark among the automatic steps');
        self::assertMatchesRegularExpression('/\d of 6/', $page->filter('.menu-item a[href="/dashboard/operator/setup"] .menu-note')->text());
    }

    public function testTheSelfTestClientIsLabelledAndTheClientViewsHavePlainAddresses(): void
    {
        $diagnostics = $this->container()->get(ApplicationDiagnostics::class);
        $client = $diagnostics->systemTestClient();
        self::assertSame($client->getId()->toRfc4122(), $diagnostics->systemTestClient()->getId()->toRfc4122(), 'one, made again only when missing');
        self::assertSame('system', Db::owner()->fetchOne('SELECT origin FROM clients WHERE id = ?', [$client->getId()->toRfc4122()]));
        $this->signIn($this->newUser(true));
        $row = $this->crawler(self::BASE.'?q='.rawurlencode($client->getCompanyName()))->filter('tbody tr')->first();
        self::assertSame('internal: self-tests', $row->filter('.tag')->text());
        foreach (['waiting', 'active', 'throttled', 'suspended', 'closed', 'invited', 'sign-ups'] as $view) {
            self::assertSame(200, $this->page(self::BASE."/$view")->getStatusCode(), $view);
        }
        self::assertSame(404, $this->page(self::BASE.'/everything')->getStatusCode());
        self::assertStringNotContainsString('origin=', (string) $this->page(self::BASE)->getContent(), 'no internal values in the addresses');
    }
}
