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
 * Sending domains set up in the client dashboard (owner decision 2026-10-11): a client admin adds
 * a domain within the client's limit, verification requests the DKIM key from the host agent, the
 * agent's result shows the records to publish, and the published record leads to activation (by
 * the button or by the agent's periodic call). The host agent is played through its console
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

        $r = $this->submit($base, $base, ['domain' => ' '.strtoupper($name).'. ']);
        self::assertSame(302, $r->getStatusCode());
        $row = Db::owner()->fetchAssociative('SELECT id::text AS id, status, dkim_status, verification_token FROM sending_domains WHERE client_id = ? AND domain = ?',
            [$client->getId()->toRfc4122(), $name]);
        self::assertSame(['pending', 'not_configured'], [$row['status'], $row['dkim_status']]);
        self::assertSame(['user', $admin->getId()->toRfc4122()], array_values(Db::owner()->fetchAssociative(
            "SELECT actor_type, actor_id FROM audit_log WHERE action = 'sending_domain.created' AND target_id = ?", [$row['id']])));

        $page = $this->crawler($base);
        self::assertStringContainsString("$name is added", $page->filter('.flash')->text());
        $card = $page->filter("#d-{$row['id']}");
        self::assertStringContainsString('Waiting for the TXT record', $card->text());
        self::assertStringContainsString("_smarthost-verification.$name", $card->text());
        self::assertStringContainsString('smarthost-verification='.$row['verification_token'], $card->text());
        self::assertSame('TXT verified', $card->filter('.stepper li.current')->text(), 'the next step is the TXT verification');
        self::assertSame(1, $card->filter("form[action=\"$base/{$row['id']}/verify\"]")->count());

        // An invalid or duplicate domain is refused with the reason.
        $this->submit($base, $base, ['domain' => 'not a domain']);
        self::assertStringContainsString('is not a valid domain name', $this->crawler($base)->filter('.flash')->text());
        $this->submit($base, $base, ['domain' => $name]);
        self::assertStringContainsString('already registered for this client', $this->crawler($base)->filter('.flash')->text());
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM sending_domains WHERE client_id = ?', [$client->getId()->toRfc4122()]));
    }

    public function testOnlyClientAdminsAddDomainsAndOnlyWithCsrf(): void
    {
        $client = $this->newClient();
        $base = $this->base($client);
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Member));
        $page = $this->crawler($base);
        self::assertSame(0, $page->filter("form[action=\"$base\"]")->count(), 'no add form for members');
        self::assertStringContainsString('Client admins add sending domains', $page->text());
        $this->browser->request('POST', $base, ['domain' => self::domainName(), '_token' => 'x']);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode(), 'a member cannot add');

        $this->browser->request('POST', '/dashboard/logout', ['_csrf_token' => $page->filter('form input[name="_csrf_token"]')->attr('value')]);
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));
        $this->browser->request('POST', $base, ['domain' => self::domainName(), '_token' => 'forged']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
        self::assertSame(405, $this->page("$base/".SchemaFixtures::id().'/dkim')->getStatusCode(), 'no mutating GET');
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM sending_domains WHERE client_id = ?', [$client->getId()->toRfc4122()]));
    }

    public function testTheDomainLimitIsEnforced(): void
    {
        $client = $this->newClient();
        $this->container()->get(ClientLimitAdministration::class)->set($this->reload($client), ['max_sending_domains' => 1], self::actor(), 'test limit');
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));
        $base = $this->base($client);

        $this->submit($base, $base, ['domain' => self::domainName()]);
        $page = $this->crawler($base);
        self::assertStringContainsString('1 of at most 1', $page->filter('.pagehead .lead')->text());
        self::assertSame(0, $page->filter("form[action=\"$base\"]")->count(), 'no add form at the limit');
        self::assertStringContainsString('Ask the operator for a higher limit', $page->text());

        // Posting anyway (with a valid token from another form) is refused by the service.
        $this->browser->request('POST', $base, ['domain' => self::domainName(), '_token' => $page->filter('input[name="_token"]')->attr('value')]);
        self::assertStringContainsString('its limit is 1', $this->crawler($base)->filter('.flash')->text());
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM sending_domains WHERE client_id = ?', [$client->getId()->toRfc4122()]));
    }

    public function testTheInstallationCeilingAppliesToClientsWithoutTheirOwnLimit(): void
    {
        $this->withEnv(['APP_CLIENT_SENDING_DOMAIN_LIMIT' => '1'], function (): void {
            $client = $this->newClient();
            $this->verifiedDomain($client);
            $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));
            $base = $this->base($client);
            $page = $this->crawler($base);
            self::assertSame(0, $page->filter("form[action=\"$base\"]")->count());
            $this->browser->request('POST', $base, ['domain' => self::domainName(), '_token' => $page->filter('input[name="_token"]')->attr('value')]);
            self::assertStringContainsString('its limit is 1', $this->crawler($base)->filter('.flash')->text());
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

        $this->submit($base, "$base/$id/verify");
        self::assertSame('verified', $this->row($id)['status']);
        $request = Db::owner()->fetchAssociative("SELECT id::text AS id, params_json, requested_by_user_id::text AS by FROM system_requests WHERE action = 'dkim.generate' AND status = 'pending' AND params_json->>'domain' = ?", [$name]);
        $params = json_decode($request['params_json'], true);
        self::assertSame($id, $params['sending_domain_id']);
        self::assertMatchesRegularExpression('/^cm\d{6}[0-9a-f]{4}$/', $params['selector']);
        self::assertSame($member->getId()->toRfc4122(), $request['by'], 'requested in the name of the user whose check verified the domain');
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'system.request.dkim.generate' AND target_id = ? AND actor_type = 'user'", [$request['id']]));
        $page = $this->crawler($base);
        $flashes = implode(' ', $page->filter('.flash')->each(static fn ($f): string => $f->text()));
        self::assertStringContainsString("$name is verified", $flashes);
        self::assertStringContainsString('A DKIM key for '.$name.' is requested', $flashes);
        self::assertStringContainsString('DKIM key being generated', $page->filter("#d-$id")->text());
        self::assertSame(0, $page->filter("#d-$id form")->count(), 'nothing to press while the host agent works');

        // The host agent generates the key and returns only the public half.
        $key = self::publicKey();
        $this->agentFinishes('dkim.generate', $name, ['public_key' => $key, 'record' => ['name' => "{$params['selector']}._domainkey.$name", 'value' => "v=DKIM1; k=rsa; p=$key"]]);
        self::assertSame(['verified', 'pending_dns', $params['selector'], $key], array_values(array_slice($this->row($id), 0, 4)));
        self::assertSame(['system', 'host-agent'], array_values(Db::owner()->fetchAssociative(
            "SELECT actor_type, actor_id FROM audit_log WHERE action = 'sending_domain.dkim_changed' AND target_id = ? ORDER BY occurred_at DESC LIMIT 1", [$id])));

        $card = $this->crawler($base)->filter("#d-$id");
        self::assertStringContainsString('DKIM pending in DNS', $card->filter('.card-head')->text());
        self::assertSame('DKIM pending in DNS', $card->filter('.stepper li.current')->text());
        self::assertSame(2, $card->filter('.stepper li.done')->count(), 'added and TXT verified are done');
        $text = $card->text();
        foreach (["{$params['selector']}._domainkey.$name", "v=DKIM1; k=rsa; p=$key", "_dmarc.$name", "v=DMARC1; p=none; rua=mailto:dmarc-reports@$name",
            'bounce.smarthost.localhost', 'nothing to add for catto-mail'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        self::assertSame(1, $card->filter("form[action=\"$base/$id/verify\"]")->count(), 'Check DNS now for the DKIM record');

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
        self::assertStringContainsString('Verified, no DKIM key yet', $this->crawler($base)->filter("#d-$id")->text(), 'verified before this page requested keys');

        $this->submit($base, "$base/$id/dkim");
        $this->agentFinishes('dkim.generate', $domain->getDomain(), [], true);
        self::assertSame('not_configured', $this->row($id)['dkim_status']);
        $card = $this->crawler($base)->filter("#d-$id");
        self::assertStringContainsString('DKIM key not generated', $card->text());
        self::assertSame('Request the DKIM key again', $card->filter("form[action=\"$base/$id/dkim\"] button")->text());

        // A succeeded generation without a usable public key changes nothing either.
        $this->submit($base, "$base/$id/dkim");
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
        $member = $this->newUser(false, $client, ClientMembershipRole::Member);
        $this->signIn($member);
        $this->browser->disableReboot();

        $this->submit($base, "$base/$id/verify");
        self::assertStringContainsString("No DKIM record found at $record yet", $this->crawler($base)->filter('.flash')->text());
        self::assertSame("No DKIM record found at $record yet.", $this->row($id)['last_check_error']);
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'sending_domain.dkim_check_failed' AND target_id = ?", [$id]));

        $this->dns()->set($record, ['v=DKIM1; k=rsa; p='.self::publicKey()]);
        $this->submit($base, "$base/$id/verify");
        self::assertStringContainsString('does not match', $this->crawler($base)->filter('.flash')->text());
        self::assertFalse(Db::owner()->fetchOne("SELECT 1 FROM system_requests WHERE action = 'dkim.activate' AND params_json->>'domain' = ?", [$name]));

        // The matching record (a long value's strings arrive concatenated from the resolver).
        $this->dns()->set($record, ['v=DKIM1; k=rsa; p='.$key]);
        $this->submit($base, "$base/$id/verify");
        self::assertStringContainsString('The host agent activates the key', $this->crawler($base)->filter('.flash')->text());
        self::assertNull($this->row($id)['last_check_error']);
        $request = Db::owner()->fetchAssociative("SELECT id::text AS id, params_json FROM system_requests WHERE action = 'dkim.activate' AND status = 'pending' AND params_json->>'domain' = ?", [$name]);
        self::assertSame(['domain' => $name, 'selector' => 'cm202610abcd', 'sending_domain_id' => $id], json_decode($request['params_json'], true));
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'system.request.dkim.activate' AND target_id = ? AND actor_id = ?",
            [$request['id'], $member->getId()->toRfc4122()]));
        $card = $this->crawler($base)->filter("#d-$id");
        self::assertStringContainsString('Activating DKIM', $card->text());
        self::assertSame(0, $card->filter('form')->count());

        // The agent activates the selector (it compared DNS with the private key itself) and the application records it.
        $this->agentFinishes('dkim.activate', $name);
        self::assertSame(['verified', 'active', 'cm202610abcd', $key], array_values(array_slice($this->row($id), 0, 4)));
        $card = $this->crawler($base)->filter("#d-$id");
        self::assertStringContainsString('Active', $card->filter('.card-head')->text());
        self::assertSame(0, $card->filter('.stepper')->count(), 'an active domain shows its state, not the steps');
        self::assertStringContainsString("v=DKIM1; k=rsa; p=$key", $card->filter('details')->text(), 'the records stay available');
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
        self::assertStringContainsString('No DKIM record found', (string) $this->row($unpublished->getId()->toRfc4122())['last_check_error']);
        self::assertSame($before, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'sending_domain.dkim_check_failed'"), 'the periodic check does not fill the audit log');

        // Checked a moment ago: not again until the interval passed; the waiting activation is not requested twice.
        $this->dns()->set('cm202610abcd._domainkey.'.$unpublished->getDomain(), ['v=DKIM1; k=rsa; p='.self::publicKey()]);
        $this->agent('domains');
        self::assertStringContainsString('No DKIM record found', (string) $this->row($unpublished->getId()->toRfc4122())['last_check_error']);
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
        $token = $page->filter('input[name="_token"]')->attr('value');
        foreach ([$bPending, $bVerified] as $d) {
            self::assertStringNotContainsString($d->getDomain(), (string) $this->browser->getResponse()->getContent());
        }

        self::assertSame(404, $this->page($bBase)->getStatusCode());
        $attempts = [[$bBase, ['domain' => self::domainName()]]];
        foreach ([$bPending, $bVerified] as $d) {
            $did = $d->getId()->toRfc4122();
            $attempts[] = ["$aBase/$did/verify", []];
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
        $this->submit($base, "$base/{$domain->getId()->toRfc4122()}/dkim");
        self::assertStringContainsString('also verified for another client', $this->crawler($base)->filter('.flash')->text());
        self::assertFalse(Db::owner()->fetchOne("SELECT 1 FROM system_requests WHERE action = 'dkim.generate' AND params_json->>'domain' = ?", [$name]));
    }

    public function testSuspendedClientsAddNoDomainsAndGetNoKeys(): void
    {
        $client = $this->newClient(ClientStatus::Suspended);
        $domain = $this->verifiedDomain($client, self::domainName());
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));
        $base = $this->base($client);
        $page = $this->crawler($base);
        self::assertSame(0, $page->filter("form[action=\"$base\"]")->count());
        self::assertSame(0, $page->filter("form[action=\"$base/{$domain->getId()->toRfc4122()}/dkim\"]")->count());
        $token = $page->filter('input[name="_token"]')->attr('value');
        $this->browser->request('POST', $base, ['domain' => self::domainName(), '_token' => $token]);
        self::assertStringContainsString('cannot add sending domains', $this->crawler($base)->filter('.flash')->text());
        $this->browser->request('POST', "$base/{$domain->getId()->toRfc4122()}/dkim", ['_token' => $token]);
        self::assertStringContainsString('suspended or closed client gets no new DKIM keys', $this->crawler($base)->filter('.flash')->text());
    }

    public function testAKeyTheOperatorSetUpByHandIsShownWithoutARecord(): void
    {
        $client = $this->newClient();
        $domain = $this->verifiedDomain($client, self::domainName());
        $this->container()->get(SendingDomainService::class)->setDkim($this->reload($domain), DkimStatus::Active, 's2026a', self::actor());
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Viewer));
        $card = $this->crawler($this->base($client))->filter("#d-{$domain->getId()->toRfc4122()}");
        self::assertStringContainsString('DKIM signing is active with selector s2026a', $card->text());
        self::assertSame(0, $card->filter('.stepper')->count(), 'an active domain shows its state, not the steps');
        self::assertSame(0, $card->filter('form')->count(), 'viewers check nothing');
        self::assertSame(0, $card->filter('table')->count(), 'no record without the recorded public half');
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
