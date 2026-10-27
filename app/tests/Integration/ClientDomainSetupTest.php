<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Client\ClientLimitAdministration;
use App\Domain\DkimSetup;
use App\Domain\DomainRuleViolation;
use App\Domain\SendingDomainService;
use App\Entity\Client;
use App\Entity\SendingDomain;
use App\Enum\ClientMembershipRole;
use App\Enum\ClientStatus;
use App\Enum\DkimStatus;
use App\Enum\SystemRequestAction;
use App\System\SystemRequests;
use App\Tests\Schema\SchemaFixtures;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use App\Tests\Support\StubTxtResolver;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Sending domains set up in the client dashboard (owner decisions 2026-10-11 and 2026-10-27): a
 * client admin adds a domain on its own page within the client's limit, the domain's steps page
 * shows one step at a time, verification requests the DKIM key from the host agent, the agent's
 * result shows the record to publish, and the published record leads to activation (by Check now,
 * the page's own check or the agent's periodic call), after which the admins are emailed. The host agent is played through its console
 * protocol (smarthost:system:agent claim/finish/domains); nothing here touches OpenDKIM.
 */
final class ClientDomainSetupTest extends DashboardTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::owner()->executeStatement("UPDATE system_requests SET status = 'cancelled', finished_at = now(), started_at = COALESCE(started_at, now()) WHERE status IN ('pending', 'running')");
    }

    /** A public key as the agent returns it: the base64 p= value of a 2048-bit RSA key's size. */
    private static function publicKey(): string
    {
        return base64_encode(random_bytes(294));
    }

    private static function domainName(): string
    {
        return 'news-'.bin2hex(random_bytes(4)).'.example.org';
    }

    private function dns(): StubTxtResolver
    {
        return $this->container()->get(\App\Domain\Dns\TxtResolver::class);
    }

    /** The agent's console protocol, in the browser's own kernel (the DNS stub and the database are shared). */
    private function agent(string $action, ?string $id = null, ?array $stdin = null, bool $failed = false): CommandTester
    {
        $this->container();   // a closed entity manager or an enabled tenant filter from a request is reset
        $t = new CommandTester((new Application(static::$kernel))->find('smarthost:system:agent'));
        if (null !== $stdin) {
            $t->setInputs([json_encode($stdin, \JSON_THROW_ON_ERROR)]);
        }
        $t->execute(['action' => $action] + (null === $id ? [] : ['request-id' => $id]) + ($failed ? ['--failed' => true] : []), ['interactive' => false]);
        self::assertSame(0, $t->getStatusCode(), $t->getDisplay());

        return $t;
    }

    /** The agent claims the waiting requests and finishes the one for $domain with $result. */
    private function agentFinishes(string $action, string $domain, array $result = [], bool $failed = false): array
    {
        $claimed = json_decode(trim($this->agent('claim')->getDisplay()), true);
        $mine = array_values(array_filter($claimed, static fn (array $r): bool => $r['action'] === $action && $r['params']['domain'] === $domain));
        self::assertCount(1, $mine, "one $action request for $domain");
        $this->agent('finish', $mine[0]['id'], ['summary' => 'done by the test agent', 'result' => $result], $failed);

        return $mine[0];
    }

    private function row(string $domainId): array
    {
        return Db::owner()->fetchAssociative('SELECT status, dkim_status, dkim_selector, dkim_public_key, last_check_error FROM sending_domains WHERE id = ?', [$domainId]);
    }

    private function base(Client $client): string
    {
        return '/dashboard/c/'.$client->getId()->toRfc4122().'/sending-domains';
    }

    /** Adds a domain on its own page (owner decision 2026-10-27), as a client admin does. */
    private function add(Client $client, string $name): \Symfony\Component\HttpFoundation\Response
    {
        $base = $this->base($client);

        return $this->submit("$base/new", "$base/new", ['domain' => $name]);
    }

    /** The domain's steps page. */
    private function steps(Client $client, string $domainId): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->crawler($this->base($client).'/'.$domainId);
    }

    private function flashes(\Symfony\Component\DomCrawler\Crawler $page): string
    {
        return implode(' ', $page->filter('.flash')->each(static fn ($f): string => $f->text()));
    }

    /** A verified domain waiting for its DKIM record, as the agent's generate result leaves it. */
    private function pendingDns(Client $client, string $key): SendingDomain
    {
        $domain = $this->verifiedDomain($client, self::domainName());
        $this->container()->get(SendingDomainService::class)->setDkim($this->reload($domain), DkimStatus::PendingDns, 'cm202610abcd', self::actor(), $key);
        // Verified a while ago: the periodic check looks at a domain once its last check is older than the interval.
        Db::owner()->executeStatement("UPDATE sending_domains SET last_checked_at = now() - interval '1 hour' WHERE id = ?", [$domain->getId()->toRfc4122()]);

        return $domain;
    }

    public function testClientAdminAddsADomainAndSeesItsTxtChallenge(): void
    {
        $client = $this->newClient();
        $admin = $this->newUser(false, $client, ClientMembershipRole::Admin);
        $name = self::domainName();
        $this->signIn($admin);
        $base = $this->base($client);
        self::assertSame(1, $this->crawler($base)->filter(".pagehead a[href=\"$base/new\"]")->count(), 'Add a domain leads to its own page');

        $r = $this->add($client, ' '.strtoupper($name).'. ');
        self::assertSame(302, $r->getStatusCode());
        $row = Db::owner()->fetchAssociative('SELECT id::text AS id, status, dkim_status, verification_token FROM sending_domains WHERE client_id = ? AND domain = ?',
            [$client->getId()->toRfc4122(), $name]);
        self::assertSame("$base/{$row['id']}", $r->headers->get('Location'), 'straight on to its steps');
        self::assertSame(['pending', 'not_configured'], [$row['status'], $row['dkim_status']]);
        self::assertSame(['user', $admin->getId()->toRfc4122()], array_values(Db::owner()->fetchAssociative(
            "SELECT actor_type, actor_id FROM audit_log WHERE action = 'sending_domain.created' AND target_id = ?", [$row['id']])));

        $page = $this->steps($client, $row['id']);
        self::assertSame('Prove it is yours', $page->filter('.guide-steps li.current')->text());
        self::assertSame(["_smarthost-verification.$name", 'smarthost-verification='.$row['verification_token']],
            $page->filter('.record [data-copy-target="source"]')->slice(0, 1)->each(static fn ($c): string => $c->text())
            + [1 => $page->filter('.record [data-copy-target="source"]')->last()->text()], 'each value with its copy button');
        self::assertSame(1, $page->filter("form[action=\"$base/{$row['id']}/verify\"]")->count(), 'Check now');
        self::assertSame(1, $page->filter('[data-controller="dnscheck"]')->count(), 'the page checks by itself');
        self::assertStringContainsString('Step 1: prove it is yours', $this->crawler($base)->filter('tbody')->text());

        // An invalid or duplicate domain is refused on the page, with what was typed.
        $r = $this->add($client, 'not a domain');
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('is not a valid domain name', self::text($r));
        self::assertStringContainsString('already registered for this client', self::text($this->add($client, $name)));
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM sending_domains WHERE client_id = ?', [$client->getId()->toRfc4122()]));
    }

    public function testOnlyClientAdminsAddDomainsAndOnlyWithCsrf(): void
    {
        $client = $this->newClient();
        $base = $this->base($client);
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Member));
        $page = $this->crawler($base);
        self::assertSame(0, $page->filter("a[href=\"$base/new\"]")->count(), 'no Add a domain for members');
        self::assertSame(404, $this->page("$base/new")->getStatusCode());
        $this->browser->request('POST', "$base/new", ['domain' => self::domainName(), '_token' => 'x']);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode(), 'a member cannot add');

        $this->browser->request('POST', '/dashboard/logout', ['_csrf_token' => $page->filter('form input[name="_csrf_token"]')->attr('value')]);
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));
        $this->browser->request('POST', "$base/new", ['domain' => self::domainName(), '_token' => 'forged']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
        self::assertSame(405, $this->page("$base/".SchemaFixtures::id().'/dkim')->getStatusCode(), 'no mutating GET');
        self::assertSame(405, $this->page("$base/".SchemaFixtures::id().'/check')->getStatusCode(), 'the page\'s check is a POST');
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM sending_domains WHERE client_id = ?', [$client->getId()->toRfc4122()]));
    }

    public function testTheDomainLimitIsEnforced(): void
    {
        $client = $this->newClient();
        $this->container()->get(ClientLimitAdministration::class)->set($this->reload($client), ['max_sending_domains' => 1], self::actor(), 'test limit');
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));
        $base = $this->base($client);
        $token = $this->crawler("$base/new")->filter('input[name="_token"]')->attr('value');

        $this->add($client, self::domainName());
        $page = $this->crawler($base);
        self::assertStringContainsString('1 of at most 1', $page->filter('.pagehead .lead')->text());
        self::assertSame(0, $page->filter("a[href=\"$base/new\"]")->count(), 'no Add a domain at the limit');
        $new = $this->crawler("$base/new");
        self::assertSame(0, $new->filter('form[action$="/new"]')->count());
        self::assertStringContainsString('Ask the operator of this installation for a higher limit', $new->text());

        // Posting anyway is refused by the service.
        $this->browser->request('POST', "$base/new", ['domain' => self::domainName(), '_token' => $token]);
        self::assertSame(422, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('its limit is 1', self::text($this->browser->getResponse()));
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM sending_domains WHERE client_id = ?', [$client->getId()->toRfc4122()]));
    }

    public function testTheInstallationCeilingAppliesToClientsWithoutTheirOwnLimit(): void
    {
        $this->withEnv(['APP_CLIENT_SENDING_DOMAIN_LIMIT' => '1'], function (): void {
            $client = $this->newClient();
            $domain = $this->verifiedDomain($client);
            $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));
            $base = $this->base($client);
            self::assertSame(0, $this->crawler("$base/new")->filter('form[action$="/new"]')->count());
            $token = $this->steps($client, $domain->getId()->toRfc4122())->filter('input[name="_token"]')->attr('value');
            $this->browser->request('POST', "$base/new", ['domain' => self::domainName(), '_token' => $token]);
            self::assertStringContainsString('its limit is 1', self::text($this->browser->getResponse()));
        });
    }

    public function testVerificationRequestsTheDkimKeyAndTheAgentResultShowsTheRecords(): void
    {
        $client = $this->newClient();
        $member = $this->newUser(false, $client, ClientMembershipRole::Member);
        $domain = $this->pendingDomain($client, self::domainName());
        $id = $domain->getId()->toRfc4122();
        $name = $domain->getDomain();
        $base = $this->base($client);
        $this->signIn($member);
        $this->browser->disableReboot();
        $this->dns()->set("_smarthost-verification.$name", ['smarthost-verification='.$domain->getVerificationToken()]);

        $this->submit("$base/$id", "$base/$id/verify");
        self::assertSame('verified', $this->row($id)['status']);
        $request = Db::owner()->fetchAssociative("SELECT id::text AS id, params_json, requested_by_user_id::text AS by FROM system_requests WHERE action = 'dkim.generate' AND status = 'pending' AND params_json->>'domain' = ?", [$name]);
        $params = json_decode($request['params_json'], true);
        self::assertSame($id, $params['sending_domain_id']);
        self::assertMatchesRegularExpression('/^cm\d{6}[0-9a-f]{4}$/', $params['selector']);
        self::assertSame($member->getId()->toRfc4122(), $request['by'], 'requested in the name of the user whose check verified the domain');
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'system.request.dkim.generate' AND target_id = ? AND actor_type = 'user'", [$request['id']]));
        $page = $this->steps($client, $id);
        self::assertStringContainsString("$name is verified: it is yours", $this->flashes($page));
        self::assertSame('Publish its signing key', $page->filter('.guide-steps li.current')->text());
        self::assertStringContainsString('Its signing key is being made', $page->filter('.guide-card')->text());
        self::assertSame(0, $page->filter('.guide-card form')->count(), 'nothing to press while the key is made');
        self::assertSame(1, $page->filter('[data-controller="dnscheck"][data-dnscheck-stage-value="key_requested"]')->count(), 'the page waits for it by itself');

        // The host agent generates the key and returns only the public half.
        $key = self::publicKey();
        $this->agentFinishes('dkim.generate', $name, ['public_key' => $key, 'record' => ['name' => "{$params['selector']}._domainkey.$name", 'value' => "v=DKIM1; k=rsa; p=$key"]]);
        self::assertSame(['verified', 'pending_dns', $params['selector'], $key], array_values(array_slice($this->row($id), 0, 4)));
        self::assertSame(['system', 'host-agent'], array_values(Db::owner()->fetchAssociative(
            "SELECT actor_type, actor_id FROM audit_log WHERE action = 'sending_domain.dkim_changed' AND target_id = ? ORDER BY occurred_at DESC LIMIT 1", [$id])));

        $page = $this->steps($client, $id);
        self::assertSame('Publish its signing key', $page->filter('.guide-steps li.current')->text());
        self::assertSame(1, $page->filter('.guide-steps li.done')->count(), 'proving it is yours is done');
        $values = $page->filter('.record [data-copy-target="source"]')->each(static fn ($c): string => $c->text());
        self::assertContains("{$params['selector']}._domainkey.$name", $values);
        self::assertContains("v=DKIM1; k=rsa; p=$key", $values);
        self::assertStringNotContainsString('DMARC', $page->filter('.guide-card')->text(), 'one step at a time: DMARC comes when the domain is ready');
        self::assertSame(1, $page->filter("form[action=\"$base/$id/verify\"]")->count(), 'Check now for the signing key record');

        // One key per domain: a second request is refused.
        try {
            $this->container()->get(DkimSetup::class)->requestKey($this->reload($domain), null);
            self::fail('a domain with a key gets no second one');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('already has a DKIM key', $e->getMessage());
        }
    }

    public function testAFailedGenerationCanBeRequestedAgainByAClientAdmin(): void
    {
        $client = $this->newClient();
        $domain = $this->verifiedDomain($client, self::domainName());
        $id = $domain->getId()->toRfc4122();
        $base = $this->base($client);
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));
        $this->browser->disableReboot();
        self::assertSame('Make the signing key', $this->steps($client, $id)->filter("form[action=\"$base/$id/dkim\"] button")->text(), 'verified before the page requested keys');

        $this->submit("$base/$id", "$base/$id/dkim");
        $this->agentFinishes('dkim.generate', $domain->getDomain(), [], true);
        self::assertSame('not_configured', $this->row($id)['dkim_status']);
        $page = $this->steps($client, $id);
        self::assertStringContainsString('The signing key could not be made', $page->text());
        self::assertSame('Try again', $page->filter("form[action=\"$base/$id/dkim\"] button")->text());

        // A succeeded generation without a usable public key changes nothing either.
        $this->submit("$base/$id", "$base/$id/dkim");
        $this->agentFinishes('dkim.generate', $domain->getDomain(), ['public_key' => 'not base64!']);
        self::assertSame('not_configured', $this->row($id)['dkim_status']);
    }

    public function testThePublishedDkimRecordActivatesTheKey(): void
    {
        $client = $this->newClient();
        $key = self::publicKey();
        $domain = $this->pendingDns($client, $key);
        $id = $domain->getId()->toRfc4122();
        $name = $domain->getDomain();
        $record = "cm202610abcd._domainkey.$name";
        $base = $this->base($client);
        $admin = $this->newUser(false, $client, ClientMembershipRole::Admin);
        $member = $this->newUser(false, $client, ClientMembershipRole::Member);
        $this->signIn($member);
        $this->browser->disableReboot();

        $this->submit("$base/$id", "$base/$id/verify");
        self::assertStringContainsString("No signing key record found at $record yet", $this->flashes($this->steps($client, $id)));
        self::assertSame("No signing key record found at $record yet.", $this->row($id)['last_check_error']);
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'sending_domain.dkim_check_failed' AND target_id = ?", [$id]));

        $this->dns()->set($record, ['v=DKIM1; k=rsa; p='.self::publicKey()]);
        $this->submit("$base/$id", "$base/$id/verify");
        self::assertStringContainsString('does not match', $this->flashes($this->steps($client, $id)));
        self::assertFalse(Db::owner()->fetchOne("SELECT 1 FROM system_requests WHERE action = 'dkim.activate' AND params_json->>'domain' = ?", [$name]));

        // The matching record (a long value's strings arrive concatenated from the resolver).
        $this->dns()->set($record, ['v=DKIM1; k=rsa; p='.$key]);
        $this->submit("$base/$id", "$base/$id/verify");
        $page = $this->steps($client, $id);
        self::assertStringContainsString('Signing is being switched on', $this->flashes($page));
        self::assertNull($this->row($id)['last_check_error']);
        $request = Db::owner()->fetchAssociative("SELECT id::text AS id, params_json FROM system_requests WHERE action = 'dkim.activate' AND status = 'pending' AND params_json->>'domain' = ?", [$name]);
        self::assertSame(['domain' => $name, 'selector' => 'cm202610abcd', 'sending_domain_id' => $id], json_decode($request['params_json'], true));
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'system.request.dkim.activate' AND target_id = ? AND actor_id = ?",
            [$request['id'], $member->getId()->toRfc4122()]));
        self::assertSame('Ready to send', $page->filter('.guide-steps li.current')->text());
        self::assertSame(0, $page->filter('.guide-card form')->count());

        // The agent activates the selector (it compared DNS with the private key itself), the application records it and emails the admins.
        $sent = \count(self::getMailerMessages());
        $this->agentFinishes('dkim.activate', $name);
        self::assertSame(['verified', 'active', 'cm202610abcd', $key], array_values(array_slice($this->row($id), 0, 4)));
        $ready = array_values(array_filter(\array_slice(self::getMailerMessages(), $sent), static fn ($m): bool => "$name is ready to send" === $m->getSubject()));
        self::assertCount(1, $ready, 'one email, to the one admin');
        self::assertSame($admin->getEmail(), $ready[0]->getTo()[0]->getAddress());
        self::assertStringContainsString("$base/$id", (string) $ready[0]->getTextBody());

        $page = $this->steps($client, $id);
        self::assertSame(3, $page->filter('.guide-steps li.done')->count());
        self::assertStringContainsString('Ready: ', $page->filter('.pagehead .lead')->text());
        $card = $page->filter('.guide-card')->text();
        self::assertStringContainsString("_dmarc.$name", $card, 'no DMARC record yet: one is suggested');
        self::assertStringContainsString('v=DMARC1; p=none', $card);
        self::assertStringNotContainsString('rua=', $card, 'no report address is made up');
        self::assertStringContainsString('bounce.smarthost.localhost', $card);
        self::assertStringContainsString("v=DKIM1; k=rsa; p=$key", $page->filter('.guide-card details')->text(), 'the records stay available');

        // An existing DMARC record is recognised.
        $this->dns()->set("_dmarc.$name", ['v=DMARC1; p=quarantine; rua=mailto:reports@example.net']);
        $card = $this->steps($client, $id)->filter('.guide-card')->text();
        self::assertStringContainsString('already has one. Keep yours: nothing to add.', $card);
        self::assertStringContainsString('v=DMARC1; p=quarantine; rua=mailto:reports@example.net', $card);
    }

    public function testThePageChecksByItselfQuietly(): void
    {
        $client = $this->newClient();
        $domain = $this->pendingDomain($client, self::domainName());
        $id = $domain->getId()->toRfc4122();
        $name = $domain->getDomain();
        $base = $this->base($client);
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Viewer));
        $this->browser->disableReboot();
        self::assertSame(0, $this->steps($client, $id)->filter('[data-controller="dnscheck"]')->count(), 'a viewer only reads the page');
        $this->browser->request('POST', "$base/$id/check", ['_token' => 'x']);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode(), 'and cannot make it look');

        $this->browser->getCookieJar()->clear();
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Member));
        $page = $this->steps($client, $id);
        $token = $page->filter('[data-controller="dnscheck"]')->attr('data-dnscheck-token-value');
        self::assertSame('30000', $page->filter('[data-controller="dnscheck"]')->attr('data-dnscheck-interval-value'));
        $check = function () use ($base, $id, $token): array {
            $this->browser->request('POST', "$base/$id/check", ['_token' => $token]);
            self::assertSame(200, $this->browser->getResponse()->getStatusCode());
            self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));

            return json_decode((string) $this->browser->getResponse()->getContent(), true);
        };
        $first = $check();
        self::assertSame('added', $first['stage']);
        self::assertStringContainsString('not there yet', (string) $first['message']);
        self::assertSame(0, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'sending_domain.verification_failed' AND target_id = ?", [$id]),
            'a failed automatic look is not audited');
        self::assertNotNull($this->row($id)['last_check_error'], 'it is recorded on the domain');

        // Within 20 seconds it does not look again, even when the record has appeared meanwhile.
        $this->dns()->set("_smarthost-verification.$name", ['smarthost-verification='.$domain->getVerificationToken()]);
        $second = $check();
        self::assertSame(['added', null], [$second['stage'], $second['message']]);

        // Later it finds the record and moves on: verified, and the signing key asked for.
        Db::owner()->executeStatement("UPDATE sending_domains SET last_checked_at = now() - interval '1 minute' WHERE id = ?", [$id]);
        $third = $check();
        self::assertSame('key_requested', $third['stage']);
        self::assertSame('verified', $this->row($id)['status']);
        self::assertSame(['system', 'domain-page'], array_values(Db::owner()->fetchAssociative(
            "SELECT actor_type, actor_id FROM audit_log WHERE action = 'sending_domain.verified' AND target_id = ?", [$id])));
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM system_requests WHERE action = 'dkim.generate' AND params_json->>'sending_domain_id' = ?", [$id]));
    }

    public function testTheHostAgentActivatesPublishedRecordsByItself(): void
    {
        $client = $this->newClient();
        $key = self::publicKey();
        $published = $this->pendingDns($client, $key);
        $unpublished = $this->pendingDns($client, self::publicKey());
        $this->dns()->set('cm202610abcd._domainkey.'.$published->getDomain(), ['v=DKIM1; k=rsa; p='.$key]);
        $before = (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'sending_domain.dkim_check_failed'");

        $out = json_decode(trim($this->agent('domains')->getDisplay()), true);
        self::assertGreaterThanOrEqual(2, $out['checked']);
        self::assertGreaterThanOrEqual(1, $out['activation_requested']);
        $request = Db::owner()->fetchAssociative("SELECT id::text AS id, requested_by_user_id FROM system_requests WHERE action = 'dkim.activate' AND status = 'pending' AND params_json->>'sending_domain_id' = ?",
            [$published->getId()->toRfc4122()]);
        self::assertNull($request['requested_by_user_id'], 'nobody pressed a button');
        self::assertSame(['system', 'dkim-setup'], array_values(Db::owner()->fetchAssociative('SELECT actor_type, actor_id FROM audit_log WHERE target_id = ?', [$request['id']])));
        self::assertStringContainsString('No signing key record found', (string) $this->row($unpublished->getId()->toRfc4122())['last_check_error']);
        self::assertSame($before, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'sending_domain.dkim_check_failed'"), 'the periodic check does not fill the audit log');

        // Checked a moment ago: not again until the interval passed; the waiting activation is not requested twice.
        $this->dns()->set('cm202610abcd._domainkey.'.$unpublished->getDomain(), ['v=DKIM1; k=rsa; p='.self::publicKey()]);
        $this->agent('domains');
        self::assertStringContainsString('No signing key record found', (string) $this->row($unpublished->getId()->toRfc4122())['last_check_error']);
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM system_requests WHERE action = 'dkim.activate' AND params_json->>'sending_domain_id' = ?", [$published->getId()->toRfc4122()]));

        $this->agentFinishes('dkim.activate', $published->getDomain());
        self::assertSame('active', $this->row($published->getId()->toRfc4122())['dkim_status']);
    }

    public function testAClientCannotTouchAnotherClientsDomains(): void
    {
        $a = $this->newClient(ClientStatus::Active, 'Client A');
        $b = $this->newClient(ClientStatus::Active, 'Client B Secret Name');
        $aDomain = $this->verifiedDomain($a, self::domainName());
        $bPending = $this->pendingDomain($b, self::domainName());
        $bVerified = $this->verifiedDomain($b, self::domainName());
        $this->signIn($this->newUser(false, $a, ClientMembershipRole::Admin));
        $aBase = $this->base($a);
        $bBase = $this->base($b);
        $page = $this->crawler($aBase);
        $token = $this->steps($a, $aDomain->getId()->toRfc4122())->filter('input[name="_token"]')->attr('value');
        foreach ([$bPending, $bVerified] as $d) {
            self::assertStringNotContainsString($d->getDomain(), (string) $this->browser->getResponse()->getContent());
        }

        self::assertSame(404, $this->page($bBase)->getStatusCode());
        $attempts = [["$bBase/new", ['domain' => self::domainName()]]];
        foreach ([$bPending, $bVerified] as $d) {
            $did = $d->getId()->toRfc4122();
            self::assertSame(404, $this->page("$aBase/$did")->getStatusCode(), 'another client\'s domain is not found');
            $attempts[] = ["$aBase/$did/verify", []];
            $attempts[] = ["$aBase/$did/check", []];
            $attempts[] = ["$aBase/$did/dkim", []];
            $attempts[] = ["$bBase/$did/verify", []];
            $attempts[] = ["$bBase/$did/dkim", []];
        }
        foreach ($attempts as [$uri, $fields]) {
            $this->browser->request('POST', $uri, $fields + ['_token' => $token]);
            self::assertSame(404, $this->browser->getResponse()->getStatusCode(), $uri);
            self::assertStringNotContainsString('Client B Secret Name', (string) $this->browser->getResponse()->getContent());
        }
        self::assertSame(2, (int) Db::owner()->fetchOne('SELECT count(*) FROM sending_domains WHERE client_id = ?', [$b->getId()->toRfc4122()]));
        self::assertFalse(Db::owner()->fetchOne("SELECT 1 FROM system_requests WHERE params_json->>'sending_domain_id' IN (?, ?)",
            [$bPending->getId()->toRfc4122(), $bVerified->getId()->toRfc4122()]));

        // An agent result changes only the domain the request names, and only if the name matches it.
        $this->container()->get(DkimSetup::class)->requestFinished('dkim.generate',
            ['domain' => $aDomain->getDomain(), 'selector' => 'cm202610beef', 'sending_domain_id' => $bVerified->getId()->toRfc4122()], true, ['public_key' => self::publicKey()]);
        self::assertSame('not_configured', $this->row($bVerified->getId()->toRfc4122())['dkim_status']);
        self::assertSame('not_configured', $this->row($aDomain->getId()->toRfc4122())['dkim_status']);
    }

    public function testADomainVerifiedForAnotherClientGetsNoSelfServiceKey(): void
    {
        $name = self::domainName();
        $this->verifiedDomain($this->newClient(), $name);
        $client = $this->newClient();
        $domain = $this->verifiedDomain($client, $name);
        $base = $this->base($client);
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));
        $id = $domain->getId()->toRfc4122();
        $this->submit("$base/$id", "$base/$id/dkim");
        self::assertStringContainsString('also verified for another client', $this->flashes($this->steps($client, $id)));
        self::assertFalse(Db::owner()->fetchOne("SELECT 1 FROM system_requests WHERE action = 'dkim.generate' AND params_json->>'domain' = ?", [$name]));
    }

    public function testSuspendedClientsAddNoDomainsAndGetNoKeys(): void
    {
        $client = $this->newClient(ClientStatus::Suspended);
        $domain = $this->verifiedDomain($client, self::domainName());
        $id = $domain->getId()->toRfc4122();
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));
        $base = $this->base($client);
        $page = $this->crawler($base);
        self::assertSame(0, $page->filter("a[href=\"$base/new\"]")->count());
        $token = $this->steps($client, $id)->filter('input[name="_token"]')->attr('value');
        $this->browser->request('POST', "$base/new", ['domain' => self::domainName(), '_token' => $token]);
        self::assertStringContainsString('cannot add sending domains', self::text($this->browser->getResponse()));
        $this->browser->request('POST', "$base/$id/dkim", ['_token' => $token]);
        self::assertStringContainsString('suspended or closed client gets no new DKIM keys', $this->flashes($this->steps($client, $id)));
    }

    public function testAKeyTheOperatorSetUpByHandIsShownWithoutARecord(): void
    {
        $client = $this->newClient();
        $domain = $this->verifiedDomain($client, self::domainName());
        $this->container()->get(SendingDomainService::class)->setDkim($this->reload($domain), DkimStatus::Active, 's2026a', self::actor());
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Viewer));
        $page = $this->steps($client, $domain->getId()->toRfc4122());
        self::assertStringContainsString('Ready: ', $page->filter('.pagehead .lead')->text());
        self::assertSame(0, $page->filter('.guide-card form')->count(), 'viewers check nothing');
        self::assertStringNotContainsString('Signing key', $page->filter('details')->text(), 'no record without the recorded public half');
        self::assertStringContainsString('Ready to send', $this->crawler($this->base($client))->filter('tbody')->text());
    }

    public function testDkimRequestsWaitPerDomainAndANewSelectorForgetsTheStoredKey(): void
    {
        $admin = $this->reload($this->newUser(false, null, roleKey: 'ADMIN'));
        $requests = $this->container()->get(SystemRequests::class);
        $one = self::domainName();
        $requests->request(SystemRequestAction::DkimGenerate, ['domain' => $one, 'selector' => 's1'], $admin);
        $requests->request(SystemRequestAction::DkimGenerate, ['domain' => self::domainName(), 'selector' => 's1'], $admin);   // another domain: fine
        try {
            $requests->request(SystemRequestAction::DkimActivate, ['domain' => $one, 'selector' => 's1'], $admin);
            self::fail('one DKIM request per domain waits at a time');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString("A DKIM request for $one is already waiting", $e->getMessage());
        }
        try {
            $requests->request(SystemRequestAction::DkimGenerate, ['domain' => self::domainName(), 'selector' => 's1', 'sending_domain_id' => 'nope'], $admin);
            self::fail('the sending domain is a UUID');
        } catch (DomainRuleViolation) {
        }

        // The operator's console command keeps working; another selector forgets the self-service key.
        $client = $this->newClient();
        $domain = $this->pendingDns($client, self::publicKey());
        $this->container()->get(SendingDomainService::class)->setDkim($this->reload($domain), DkimStatus::Active, 's2026a', self::actor());
        self::assertSame(['active', 's2026a', null], array_values(array_slice($this->row($domain->getId()->toRfc4122()), 1, 3)));
    }
}
