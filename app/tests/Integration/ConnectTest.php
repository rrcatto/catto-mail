<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Client\ClientLimitAdministration;
use App\Connect\ConnectService;
use App\Entity\Client;
use App\Entity\User;
use App\Entity\WebhookEndpoint;
use App\Enum\ClientMembershipRole;
use App\Enum\ClientStatus;
use App\Security\ApiKeyManager;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use App\Tests\Support\StubHostResolver;
use App\Webhook\WebhookEndpointService;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Application connect (specification 2.12): an application is connected with one approval, in
 * an authorisation-code flow with PKCE. The whole flow by signing in and by opening an account;
 * the account-creation option following APP_PUBLIC_ONBOARDING_ENABLED; codes that are wrong,
 * reused or expired and a wrong code verifier; refused redirect and webhook URLs; who may
 * approve; the key and endpoint limits; disconnection by the application and by a client admin.
 * Every request comes from this test's own client address (the rate limits count per address).
 */
final class ConnectTest extends DashboardTestCase
{
    private const REDIRECT = 'https://lists.example.org/cattomail/callback';
    private const WEBHOOK = 'https://lists.example.org/cattomail/webhook';
    private const APPLICATION = 'ctnlist at lists.example.org';
    private const EVENTS = ['message.complained', 'message.hard_bounced', 'send.completed', 'send.failed', 'validation.completed', 'validation.failed'];

    private string $from;

    protected function setUp(): void
    {
        parent::setUp();
        $this->from = '10.99.'.random_int(0, 255).'.'.random_int(1, 254);
        $this->fromThisAddress();
        StubHostResolver::$answers = ['lists.example.org' => ['93.184.216.34'], 'inside.example.org' => ['10.20.30.40']];
    }

    protected function tearDown(): void
    {
        StubHostResolver::$answers = [];
        parent::tearDown();
    }

    private function fromThisAddress(): void
    {
        $this->browser->setServerParameter('REMOTE_ADDR', $this->from);
    }

    /** @return array{0: string, 1: string} a code verifier and its S256 challenge */
    private static function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    /** @return array<string, string> what the application puts in the address of the connect page */
    private static function params(string $challenge, array $override = []): array
    {
        return $override + ['application_name' => self::APPLICATION, 'redirect_uri' => self::REDIRECT, 'webhook_url' => self::WEBHOOK,
            'state' => 'state-'.bin2hex(random_bytes(6)), 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
    }

    private static function uri(array $params): string
    {
        return '/dashboard/connect?'.http_build_query($params);
    }

    private static function rows(string $sql, array $params = []): int
    {
        return (int) Db::owner()->fetchOne($sql, $params);
    }

    private function get(string $uri): Response
    {
        $this->browser->request('GET', $uri);

        return $this->browser->getResponse();
    }

    /** Submits the approval form of the connect page as the signed-in person would. */
    private function decide(Crawler $page, string $decision, ?string $clientId = null, array $override = []): Response
    {
        $form = $page->filter('form[action="/dashboard/connect"]');
        self::assertSame(1, $form->count(), 'the approval form');
        $fields = [];
        foreach ($form->filter('input[type="hidden"]') as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        if (null !== $clientId) {
            $fields['client_id'] = $clientId;
        }
        $this->browser->request('POST', '/dashboard/connect', $override + $fields + ['decision' => $decision]);

        return $this->browser->getResponse();
    }

    /** @return array<string, string> the query of a redirect back to the application */
    private static function answer(Response $response): array
    {
        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        $location = (string) $response->headers->get('Location');
        self::assertStringStartsWith(self::REDIRECT.'?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);

        return $query;
    }

    /** An approval made through the service: [code, verifier, connection id]. */
    private function approved(Client $client, User $admin, array $override = []): array
    {
        [$verifier, $challenge] = self::pkce();
        /** @var ConnectService $connect */
        $connect = $this->service(ConnectService::class);
        $code = $connect->approve($this->reload($admin), $client->getId()->toRfc4122(), $connect->request(self::params($challenge, $override)));
        $id = (string) Db::owner()->fetchOne('SELECT id::text FROM application_connections WHERE code_hash = ?', [hash('sha256', $code)]);

        return [$code, $verifier, $id];
    }

    private function exchange(string $code, string $verifier, string $redirect = self::REDIRECT): Response
    {
        return $this->api('POST', '/v1/connect/exchange', null, ['code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirect]);
    }

    /** @return array{0: Client, 1: User} an active client and its client admin */
    private function clientWithAdmin(string $name = 'Lists Co'): array
    {
        $client = $this->newClient(ClientStatus::Active, $name);

        return [$client, $this->newUser(false, $client, ClientMembershipRole::Admin)];
    }

    private function limit(Client $client, array $limits): void
    {
        $this->service(ClientLimitAdministration::class)->set($this->reload($client), $limits, self::actor(), 'test setup');
    }

    public function testAClientAdminConnectsAnApplicationBySigningIn(): void
    {
        [$client, $admin] = $this->clientWithAdmin();
        $cid = $client->getId()->toRfc4122();
        $domain = $this->verifiedDomain($client);
        [$verifier, $challenge] = self::pkce();
        $params = self::params($challenge);

        // A visitor sees what is asked and is offered sign-in; with public sign-up off, nothing else.
        $page = $this->crawler(self::uri($params));
        self::assertStringContainsString(self::APPLICATION, $page->text());
        self::assertStringContainsString('https://lists.example.org', $page->text());
        self::assertStringContainsString(self::WEBHOOK, $page->text());
        $signIn = $page->filter('a.btn')->reduce(static fn (Crawler $a): bool => 'Sign in' === $a->text());
        self::assertSame(1, $signIn->count());
        self::assertStringStartsWith('/dashboard/login?return_to=', (string) $signIn->attr('href'));
        self::assertStringNotContainsString('/dashboard/sign-up', (string) $this->browser->getResponse()->getContent(), 'no account creation while public sign-up is off');
        self::assertStringContainsString('created by its operator', $page->text());
        self::assertSame(0, $page->filter('form')->count(), 'a visitor cannot approve anything');
        self::assertSame(0, self::rows('SELECT count(*) FROM application_connections'), 'looking stores nothing');

        // Signing in from there: the emailed link leads back to the connect step.
        $login = $this->crawler((string) $signIn->attr('href'));
        $returnTo = (string) $login->filter('input[name="return_to"]')->attr('value');
        self::assertStringStartsWith('/dashboard/connect?', $returnTo);
        $this->browser->request('POST', '/dashboard/login', ['email' => $admin->getEmail(), 'return_to' => $returnTo,
            '_csrf_token' => $login->filter('input[name="_csrf_token"]')->attr('value')]);
        self::assertStringEndsWith('/dashboard/login/sent', (string) $this->browser->getResponse()->headers->get('Location'));
        $mail = self::getMailerMessages();
        self::assertSame(1, preg_match('#https://smarthost\.localhost(/dashboard/login/verify\?token=[A-Za-z0-9_-]{43})#', (string) end($mail)->getTextBody(), $m));
        $this->browser->request('GET', $m[1]);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        self::assertSame($returnTo, $this->browser->getResponse()->headers->get('Location'), 'back to the connect step');

        // The approval page: the one eligible workspace is chosen; the form may end at the application only.
        $approve = $this->crawler($returnTo);
        self::assertSame(1, $approve->filter('input[type="radio"][name="client_id"][value="'.$cid.'"][checked]')->count());
        self::assertStringContainsString("form-action 'self' https://lists.example.org;", (string) $this->browser->getResponse()->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('No key or secret is in that address', $approve->text());
        foreach (self::EVENTS as $event) {
            self::assertStringContainsString($event, $approve->text());
        }
        self::assertSame(0, self::rows('SELECT count(*) FROM application_connections WHERE client_id = ?', [$cid]));

        // Approving: back to the application with a single-use code and the state, and nothing secret.
        $response = $this->decide($approve, 'approve', $cid);
        $answer = self::answer($response);
        self::assertSame(['code', 'state'], array_keys($answer));
        self::assertSame($params['state'], $answer['state']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $answer['code']);
        self::assertStringNotContainsString('shk_', (string) $response->headers->get('Location'));
        self::assertStringNotContainsString('whsec_', (string) $response->headers->get('Location'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $row = Db::owner()->fetchAssociative('SELECT * FROM application_connections WHERE client_id = ?', [$cid]);
        self::assertSame(['pending', self::APPLICATION, self::REDIRECT, self::WEBHOOK, hash('sha256', $answer['code']), $challenge, null, null, null],
            [$row['status'], $row['application_name'], $row['redirect_uri'], $row['webhook_url'], $row['code_hash'], $row['code_challenge'],
                $row['api_key_id'], $row['webhook_endpoint_id'], $row['exchanged_at']]);
        $seconds = (int) Db::owner()->fetchOne('SELECT extract(epoch FROM code_expires_at - created_at)::int FROM application_connections WHERE id = ?', [$row['id']]);
        self::assertGreaterThan(590, $seconds);
        self::assertLessThanOrEqual(600, $seconds, 'the code expires within 10 minutes');
        self::assertSame(0, self::rows('SELECT count(*) FROM api_keys WHERE client_id = ?', [$cid]), 'nothing exists before the exchange');
        self::assertSame(0, self::rows('SELECT count(*) FROM webhook_endpoints WHERE client_id = ?', [$cid]));
        self::assertSame(['user', $admin->getId()->toRfc4122()], array_values(Db::owner()->fetchAssociative(
            "SELECT actor_type, actor_id FROM audit_log WHERE action = 'application_connection.approved' AND target_id = ?", [$row['id']])));

        // The application exchanges the code, server to server, and receives its credentials once.
        $exchange = $this->exchange($answer['code'], $verifier);
        $credentials = $this->assertContract($exchange, 200, '/connect/exchange', 'post');
        self::assertStringContainsString('no-store', (string) $exchange->headers->get('Cache-Control'));
        self::assertSame([$row['id'], self::APPLICATION, $this->reload($client)->getCompanyName(), 'https://smarthost.localhost/v1'],
            [$credentials['connection_id'], $credentials['application_name'], $credentials['client_name'], $credentials['api_base_url']]);
        self::assertSame(self::WEBHOOK, $credentials['webhook']['url']);
        self::assertSame(self::EVENTS, $credentials['webhook']['events']);
        self::assertSame([['id' => $domain->getId()->toRfc4122(), 'domain' => $domain->getDomain(), 'status' => 'verified', 'dkim_status' => 'not_configured',
            'can_send' => true]], array_map(static fn (array $d): array => array_diff_key($d, ['verified_at' => 1]), $credentials['sending_domains']));

        // The API key (named after the application) works, and the endpoint signs with the returned secret.
        $key = Db::owner()->fetchAssociative('SELECT id::text AS id, name, key_hash, revoked_at, expires_at FROM api_keys WHERE client_id = ?', [$cid]);
        self::assertSame([self::APPLICATION, ApiKeyManager::hash($credentials['api_key']), null, null], [$key['name'], $key['key_hash'], $key['revoked_at'], $key['expires_at']]);
        $domains = $this->assertContract($this->api('GET', '/v1/sending-domains', $credentials['api_key']), 200, '/sending-domains', 'get');
        self::assertSame($credentials['sending_domains'], $domains['data']);
        $endpoint = $this->container()->get('doctrine')->getManager()->find(WebhookEndpoint::class, Uuid::fromString($credentials['webhook']['endpoint_id']));
        self::assertSame([self::WEBHOOK, 'enabled', self::EVENTS], [$endpoint->getUrl(), $endpoint->getStatus()->value, $endpoint->getSubscribedEventTypes()]);
        self::assertSame([$credentials['webhook']['signing_secret']], $this->service(WebhookEndpointService::class)->activeSecrets($endpoint));
        self::assertSame(['active', $key['id'], $credentials['webhook']['endpoint_id']], array_values(Db::owner()->fetchAssociative(
            'SELECT status, api_key_id::text, webhook_endpoint_id::text FROM application_connections WHERE id = ?', [$row['id']])));

        // Audited, without anything secret; the global-opt-out capability is not part of it.
        self::assertSame(['system', ConnectService::ACTOR], array_values(Db::owner()->fetchAssociative(
            "SELECT actor_type, actor_id FROM audit_log WHERE action = 'application_connection.connected' AND target_id = ?", [$row['id']])));
        self::assertSame(1, self::rows("SELECT count(*) FROM audit_log WHERE action = 'api_key.created' AND target_id = ?", [$key['id']]));
        self::assertSame(1, self::rows("SELECT count(*) FROM audit_log WHERE action = 'webhook_endpoint.created' AND target_id = ?", [$credentials['webhook']['endpoint_id']]));
        $audit = implode("\n", Db::owner()->fetchFirstColumn('SELECT detail_json::text FROM audit_log'));
        foreach ([$answer['code'], $verifier, $credentials['api_key'], $credentials['webhook']['signing_secret']] as $secret) {
            self::assertStringNotContainsString($secret, $audit);
        }
        self::assertFalse((bool) Db::owner()->fetchOne('SELECT can_submit_global_suppressions FROM clients WHERE id = ?', [$cid]));
        $this->assertProblem($this->api('POST', '/v1/global-suppressions', $credentials['api_key'], ['email_address' => 'reader@example.org'],
            ['Idempotency-Key' => self::key()]), 403);

        // The code worked once.
        $this->assertProblem($this->exchange($answer['code'], $verifier), 400, 'invalid-connect-code');
        self::assertSame(1, self::rows('SELECT count(*) FROM api_keys WHERE client_id = ?', [$cid]));

        // The client's dashboard lists the connection.
        $list = $this->crawler("/dashboard/c/$cid/connections");
        self::assertStringContainsString(self::APPLICATION, $list->filter('table')->text());
        self::assertStringContainsString('Connected', $list->filter('table')->text());
        self::assertStringNotContainsString($credentials['api_key'], (string) $this->browser->getResponse()->getContent());
    }

    public function testSomeoneWithoutAnAccountOpensOneAndReturnsToTheConnectStep(): void
    {
        $this->withEnv(['APP_PUBLIC_ONBOARDING_ENABLED' => 'true'], function (): void {
            $this->fromThisAddress();
            $email = 'founder-'.bin2hex(random_bytes(4)).'@connect.example.org';
            [$verifier, $challenge] = self::pkce();
            $params = self::params($challenge);

            $page = $this->crawler(self::uri($params));
            $create = $page->filter('a.btn')->reduce(static fn (Crawler $a): bool => 'Create an account' === $a->text());
            self::assertSame(1, $create->count(), 'account creation is offered while public sign-up is on');
            self::assertStringStartsWith('/dashboard/sign-up?return_to=', (string) $create->attr('href'));
            self::assertSame(1, $page->filter('a.btn')->reduce(static fn (Crawler $a): bool => 'Sign in' === $a->text())->count());

            // The public sign-up, carrying the connect step as the link's return path.
            $form = $this->crawler((string) $create->attr('href'));
            $returnTo = (string) $form->filter('input[name="return_to"]')->attr('value');
            self::assertStringStartsWith('/dashboard/connect?', $returnTo);
            $this->browser->request('POST', '/dashboard/sign-up', ['organisation' => 'Connect Bakery', 'email' => $email, 'website' => '', 'return_to' => $returnTo,
                '_csrf_token' => $form->filter('input[name="_csrf_token"]')->attr('value')]);
            self::assertStringEndsWith('/dashboard/sign-up/sent', (string) $this->browser->getResponse()->headers->get('Location'));
            self::assertSame($returnTo, Db::owner()->fetchOne('SELECT return_path FROM auth_login_tokens WHERE email = ?', [$email]));
            $link = null;
            foreach (self::getMailerMessages() as $message) {
                if ($message instanceof TemplatedEmail && $message->getTo()[0]->getAddress() === $email
                    && 1 === preg_match('#https://smarthost\.localhost(/dashboard/sign-up/verify\?token=[A-Za-z0-9_-]{43})#', (string) $message->getTextBody(), $m)) {
                    $link = $m[1];
                }
            }
            self::assertNotNull($link, 'a confirmation link was emailed');
            $confirm = $this->crawler($link)->filter('form[action="/dashboard/sign-up/verify"]');
            $this->browser->request('POST', '/dashboard/sign-up/verify', ['token' => $confirm->filter('input[name="token"]')->attr('value'),
                '_csrf_token' => $confirm->filter('input[name="_csrf_token"]')->attr('value'), 'accept_policy' => '1']);
            self::assertSame(302, $this->browser->getResponse()->getStatusCode(), (string) $this->browser->getResponse()->getContent());
            self::assertSame($returnTo, $this->browser->getResponse()->headers->get('Location'), 'the new client admin returns to the connect step');
            $cid = (string) Db::owner()->fetchOne('SELECT id::text FROM clients WHERE contact_email = ?', [$email]);

            // The new workspace (active, starter tier, this person its client admin) can be connected at once.
            $approve = $this->crawler($returnTo);
            self::assertStringContainsString('Connect Bakery', $approve->text());
            self::assertSame(1, $approve->filter('input[type="radio"][name="client_id"][value="'.$cid.'"][checked]')->count());
            $answer = self::answer($this->decide($approve, 'approve', $cid));
            self::assertSame($params['state'], $answer['state']);
            $credentials = $this->assertContract($this->exchange($answer['code'], $verifier), 200, '/connect/exchange', 'post');
            self::assertSame('Connect Bakery', $credentials['client_name']);
            self::assertSame([], $credentials['sending_domains'], 'a new account has no sending domain yet');
            $this->assertContract($this->api('GET', '/v1/sending-domains', $credentials['api_key']), 200, '/sending-domains', 'get');
            self::assertSame(1, self::rows('SELECT count(*) FROM api_keys WHERE client_id = ? AND revoked_at IS NULL', [$cid]), 'within the starter limits');
            self::assertSame(1, self::rows("SELECT count(*) FROM webhook_endpoints WHERE client_id = ? AND status = 'enabled'", [$cid]));
        });
    }

    public function testAccountCreationFollowsThePublicOnboardingSwitch(): void
    {
        [, $challenge] = self::pkce();
        $uri = self::uri(self::params($challenge));

        // Off (the default): only sign-in, and the sign-up page does not exist even with a return path.
        $off = (string) $this->get($uri)->getContent();
        self::assertStringNotContainsString('Create an account', $off);
        self::assertStringNotContainsString('/dashboard/sign-up', $off);
        self::assertStringContainsString('/dashboard/login?return_to=', $off);
        self::assertSame(404, $this->get('/dashboard/sign-up?return_to='.rawurlencode('/dashboard/connect?x=1'))->getStatusCode());
        self::assertStringNotContainsString('/dashboard/sign-up', (string) $this->get('/dashboard/login?return_to='.rawurlencode('/dashboard/connect?x=1'))->getContent());

        // On: both are offered, and each page keeps the way back.
        $this->withEnv(['APP_PUBLIC_ONBOARDING_ENABLED' => 'true'], function () use ($uri): void {
            $this->fromThisAddress();
            $on = (string) $this->get($uri)->getContent();
            self::assertStringContainsString('Create an account', $on);
            self::assertStringContainsString('/dashboard/sign-up?return_to=', $on);
            self::assertStringContainsString('/dashboard/login?return_to=', $on);
            $login = $this->crawler('/dashboard/login?return_to='.rawurlencode('/dashboard/connect?x=1'));
            self::assertStringStartsWith('/dashboard/sign-up?return_to=', (string) $login->filter('a[href^="/dashboard/sign-up"]')->attr('href'));
        });
    }

    public function testOnlyAConnectPathIsAcceptedAsTheWayBack(): void
    {
        foreach (['https://evil.example/', '//evil.example/', '/dashboard/operator', '/dashboard/connect/../operator', '/v1/connection'] as $elsewhere) {
            $login = $this->crawler('/dashboard/login?return_to='.rawurlencode($elsewhere));
            self::assertSame(0, $login->filter('input[name="return_to"]')->count(), $elsewhere);
        }
        // A signed-in person who follows such a link goes home, never elsewhere.
        [, $admin] = $this->clientWithAdmin();
        $this->signIn($admin);
        $this->fromThisAddress();
        self::assertStringNotContainsString('evil', (string) $this->get('/dashboard/login?return_to='.rawurlencode('https://evil.example/'))->headers->get('Location'));
        self::assertSame('/dashboard/connect?state=abc', $this->get('/dashboard/login?return_to='.rawurlencode('/dashboard/connect?state=abc&next=//evil.example'))->headers->get('Location'));
    }

    public function testWrongReusedAndExpiredCodesAndAWrongVerifierAreRefusedAlike(): void
    {
        [$client, $admin] = $this->clientWithAdmin();
        $cid = $client->getId()->toRfc4122();
        [$code, $verifier, $id] = $this->approved($client, $admin);
        [$otherVerifier] = self::pkce();

        $this->assertProblem($this->exchange(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $verifier), 400, 'invalid-connect-code');
        $this->assertProblem($this->exchange('not-a-code', $verifier), 400, 'invalid-connect-code');
        $this->assertProblem($this->exchange($code, $otherVerifier), 400, 'invalid-connect-code');
        $this->assertProblem($this->exchange($code, 'short'), 400, 'invalid-connect-code');
        $this->assertProblem($this->exchange($code, $verifier, 'https://lists.example.org/another/callback'), 400, 'invalid-connect-code');
        $this->assertProblem($this->api('POST', '/v1/connect/exchange', null, ['code' => $code, 'code_verifier' => $verifier]), 422, 'validation-error');
        $this->assertProblem($this->api('POST', '/v1/connect/exchange', null, '{'), 400, 'malformed-json');
        $this->assertProblem($this->api('GET', '/v1/connect/exchange', null), 405);
        self::assertSame(0, self::rows('SELECT count(*) FROM api_keys WHERE client_id = ?', [$cid]), 'a refused exchange creates nothing');
        self::assertSame('pending', Db::owner()->fetchOne('SELECT status FROM application_connections WHERE id = ?', [$id]));

        // None of that used the code up: the right verifier still works - once.
        $credentials = $this->assertContract($this->exchange($code, $verifier), 200, '/connect/exchange', 'post');
        $again = $this->assertProblem($this->exchange($code, $verifier), 400, 'invalid-connect-code');
        self::assertStringNotContainsString($credentials['api_key'], json_encode($again));
        self::assertSame(1, self::rows('SELECT count(*) FROM api_keys WHERE client_id = ?', [$cid]));
        self::assertSame(1, self::rows('SELECT count(*) FROM webhook_endpoints WHERE client_id = ?', [$cid]));

        // An expired code, and the code of an approval a client admin took back.
        [$expired, $expiredVerifier, $expiredId] = $this->approved($client, $admin, ['webhook_url' => 'https://lists.example.org/hook-2']);
        Db::owner()->executeStatement("UPDATE application_connections SET created_at = now() - interval '11 minutes', code_expires_at = now() - interval '1 minute' WHERE id = ?", [$expiredId]);
        $this->assertProblem($this->exchange($expired, $expiredVerifier), 400, 'invalid-connect-code');
        [$revoked, $revokedVerifier, $revokedId] = $this->approved($client, $admin, ['webhook_url' => 'https://lists.example.org/hook-3']);
        /** @var ConnectService $connect */
        $connect = $this->service(ConnectService::class);
        $connect->revoke($connect->find($this->reload($client), $revokedId), self::actor(), 'client');
        $this->assertProblem($this->exchange($revoked, $revokedVerifier), 400, 'invalid-connect-code');
        self::assertSame(1, self::rows('SELECT count(*) FROM api_keys WHERE client_id = ?', [$cid]));
    }

    public function testARefusedRedirectOrWebhookUrlIsExplainedAndNeverFollowed(): void
    {
        [$client, $admin] = $this->clientWithAdmin();
        [, $challenge] = self::pkce();
        $refused = [
            'redirect_uri' => ['ftp://lists.example.org/callback', 'javascript:alert(1)', '/cattomail/callback', 'https://user:secret@lists.example.org/callback',
                'https://lists.example.org/callback#fragment', 'https://lists.example.org/call back', 'https://'.str_repeat('a', 2000).'.example.org/', ''],
            'webhook_url' => ['ftp://lists.example.org/hook', 'https://user:secret@lists.example.org/hook', 'https://lists.example.org/hook#x',
                'https://inside.example.org/hook', 'https://127.0.0.1/hook', 'https://[::1]/hook', 'https://169.254.169.254/latest', 'https://no-such-host.example.org/hook', ''],
        ];
        foreach ([false, true] as $signedIn) {
            if ($signedIn) {
                $this->signIn($admin);
                $this->fromThisAddress();
            }
            foreach ($refused as $parameter => $values) {
                foreach ($values as $value) {
                    $response = $this->get(self::uri(self::params($challenge, [$parameter => $value])));
                    self::assertSame(400, $response->getStatusCode(), "$parameter=$value");
                    self::assertFalse($response->headers->has('Location'), 'never redirected to an address that was not accepted');
                    self::assertStringContainsString($parameter, self::text($response), "$parameter=$value is named");
                    self::assertSame(0, (new Crawler((string) $response->getContent()))->filter('form[action="/dashboard/connect"]')->count());
                }
            }
        }
        // The other parameters, and the approval form posted with a refused address.
        foreach ([['application_name' => ''], ['application_name' => str_repeat('x', 101)], ['state' => ''], ['state' => 'with space'],
            ['code_challenge' => 'too-short'], ['code_challenge_method' => 'plain']] as $override) {
            self::assertSame(400, $this->get(self::uri(self::params($challenge, $override)))->getStatusCode(), json_encode($override));
        }
        $approve = $this->crawler(self::uri(self::params($challenge)));
        $response = $this->decide($approve, 'approve', $client->getId()->toRfc4122(), ['redirect_uri' => 'https://evil.example.org/#steal']);
        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($response->headers->has('Location'));
        $response = $this->decide($approve, 'approve', $client->getId()->toRfc4122(), ['webhook_url' => 'https://inside.example.org/hook']);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, self::rows('SELECT count(*) FROM application_connections WHERE client_id = ?', [$client->getId()->toRfc4122()]));
    }

    public function testOnlyAClientAdminOfAnActiveClientCanApprove(): void
    {
        [$client, $admin] = $this->clientWithAdmin('Approve Co');
        $cid = $client->getId()->toRfc4122();
        [, $challenge] = self::pkce();
        $uri = self::uri(self::params($challenge));
        $none = static fn (): int => self::rows('SELECT count(*) FROM application_connections WHERE client_id = ?', [$cid]);

        // A visitor posting the form is sent back to the page (to sign in), and nothing is approved.
        $this->browser->request('POST', '/dashboard/connect', self::params($challenge) + ['client_id' => $cid, 'decision' => 'approve', '_token' => 'x']);
        self::assertSame(303, $this->browser->getResponse()->getStatusCode());
        self::assertStringStartsWith('/dashboard/connect?', (string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame(0, $none());

        // A member and a viewer of the client, an operator, and an admin of another client.
        $other = $this->newClient(ClientStatus::Active, 'Other Co');
        foreach ([$this->newUser(false, $client, ClientMembershipRole::Member), $this->newUser(false, $client, ClientMembershipRole::Viewer),
            $this->newUser(true), $this->newUser(false, $other, ClientMembershipRole::Admin)] as $person) {
            static::ensureKernelShutdown();
            $this->browser = static::createClient(['debug' => false]);
            $this->fromThisAddress();
            $this->signIn($person);
            $this->fromThisAddress();
            $page = $this->crawler($uri);
            self::assertSame(0, $page->filter('input[name="client_id"][value="'.$cid.'"]')->count(), 'the workspace cannot be chosen');
            $response = $this->decide($page, 'approve', $cid);
            self::assertSame(422, $response->getStatusCode(), 'a forged choice is refused');
            self::assertFalse($response->headers->has('Location'));
            self::assertSame(0, $none());
        }

        // The client admin: not with a stale form, not while the client is suspended or throttled; declining returns without a code.
        static::ensureKernelShutdown();
        $this->browser = static::createClient(['debug' => false]);
        $this->fromThisAddress();
        $this->signIn($admin);
        $this->fromThisAddress();
        $page = $this->crawler($uri);
        $response = $this->decide($page, 'approve', $cid, ['_token' => 'forged']);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringStartsWith('/dashboard/connect?', (string) $response->headers->get('Location'), 'a bad CSRF token approves nothing');
        self::assertSame(0, $none());
        foreach ([ClientStatus::Suspended, ClientStatus::Throttled] as $status) {
            $this->setClientStatus($client, $status);
            $page = $this->crawler($uri);
            self::assertSame(0, $page->filter('input[name="client_id"]')->count());
            self::assertStringContainsString('only to an active client', $page->text());
            self::assertSame(422, $this->decide($page, 'approve', $cid)->getStatusCode());
            self::assertSame(0, $none());
            $this->setClientStatus($client, ClientStatus::Active);
        }
        $declined = self::answer($this->decide($this->crawler($uri), 'deny', $cid));
        self::assertSame('access_denied', $declined['error']);
        self::assertArrayNotHasKey('code', $declined);
        self::assertSame(0, $none());
        self::assertSame(303, $this->decide($this->crawler($uri), 'approve', $cid)->getStatusCode());
        self::assertSame(1, $none());
    }

    public function testAMemberSeesWhyTheyCannotApprove(): void
    {
        [$client] = $this->clientWithAdmin('Member Co');
        [, $challenge] = self::pkce();
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Member));
        $this->fromThisAddress();
        $page = $this->crawler(self::uri(self::params($challenge)));
        self::assertStringContainsString('Only a client admin can connect an application; you are a member of', $page->text());
        self::assertSame(0, $page->filter('button[value="approve"]')->count(), 'nothing to approve');
        self::assertSame(1, $page->filter('button[value="deny"]')->count());
    }

    public function testAClientAtItsKeyOrEndpointLimitIsToldAndNothingIsCreated(): void
    {
        [$client, $admin] = $this->clientWithAdmin('Limit Co');
        $cid = $client->getId()->toRfc4122();
        [, $challenge] = self::pkce();
        $uri = self::uri(self::params($challenge));
        $this->signIn($admin);
        $this->fromThisAddress();
        $state = static fn (): array => [self::rows('SELECT count(*) FROM application_connections WHERE client_id = ?', [$cid]),
            self::rows('SELECT count(*) FROM api_keys WHERE client_id = ?', [$cid]), self::rows('SELECT count(*) FROM webhook_endpoints WHERE client_id = ?', [$cid])];

        // At the API-key limit.
        $this->limit($client, ['max_api_keys' => 1]);
        $this->newKey($client);
        $page = $this->crawler($uri);
        self::assertStringContainsString('already has 1 usable API key, and its limit is 1', $page->text());
        self::assertSame(0, $page->filter('input[name="client_id"]')->count());
        $response = $this->decide($page, 'approve', $cid);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('nothing was created', self::text($response));
        self::assertFalse($response->headers->has('Location'));
        self::assertSame([0, 1, 0], $state());

        // At the webhook-endpoint limit (a disabled endpoint counts too).
        $this->limit($client, ['max_api_keys' => 5, 'max_webhook_endpoints' => 1]);
        [$endpoint] = $this->service(WebhookEndpointService::class)->create($this->reload($client), 'https://lists.example.org/earlier', ['send.completed'], self::actor());
        $this->service(WebhookEndpointService::class)->setStatus($endpoint, \App\Enum\WebhookEndpointStatus::Disabled, self::actor());
        $page = $this->crawler($uri);
        self::assertStringContainsString('already has 1 webhook endpoint, and its limit is 1', $page->text());
        self::assertSame(422, $this->decide($page, 'approve', $cid)->getStatusCode());
        self::assertSame([0, 1, 1], $state());

        // With room, the approval goes through; a limit reached before the exchange refuses the exchange and creates nothing.
        $this->limit($client, ['max_api_keys' => 2, 'max_webhook_endpoints' => 2]);
        [$code, $verifier] = $this->approved($client, $admin);
        $this->newKey($client);
        $refused = $this->assertProblem($this->exchange($code, $verifier), 409, 'connect-refused');
        self::assertStringContainsString('its limit is 2', $refused['detail']);
        self::assertSame([1, 2, 1], $state());
        // The code is still good: once there is room, the same code connects.
        $this->limit($client, ['max_api_keys' => 4, 'max_webhook_endpoints' => 3]);
        $this->assertContract($this->exchange($code, $verifier), 200, '/connect/exchange', 'post');
        self::assertSame([1, 3, 2], $state());

        // A client that stopped being active between the approval and the exchange.
        [$code, $verifier] = $this->approved($client, $admin, ['webhook_url' => 'https://lists.example.org/hook-2']);
        $this->setClientStatus($client, ClientStatus::Suspended);
        $this->assertProblem($this->exchange($code, $verifier), 409, 'connect-refused');
        self::assertSame([2, 3, 2], $state());
    }

    public function testConnectingAgainReplacesTheEarlierConnectionEvenAtTheLimits(): void
    {
        [$client, $admin] = $this->clientWithAdmin('Again Co');
        $cid = $client->getId()->toRfc4122();
        $this->limit($client, ['max_api_keys' => 1, 'max_webhook_endpoints' => 1]);
        [$code, $verifier] = $this->approved($client, $admin);
        $first = $this->assertContract($this->exchange($code, $verifier), 200, '/connect/exchange', 'post');

        // The same application (the same webhook address) connects again: allowed at the limits, because it replaces.
        $this->signIn($admin);
        $this->fromThisAddress();
        [$verifier, $challenge] = self::pkce();
        $page = $this->crawler(self::uri(self::params($challenge)));
        self::assertStringContainsString('Replaces the connection of '.self::APPLICATION, $page->text());
        $answer = self::answer($this->decide($page, 'approve', $cid));
        // Until the new credentials are collected, the earlier connection keeps working.
        self::assertSame(200, $this->api('GET', '/v1/sending-domains', $first['api_key'])->getStatusCode());
        $second = $this->assertContract($this->exchange($answer['code'], $verifier), 200, '/connect/exchange', 'post');

        self::assertNotSame($first['api_key'], $second['api_key']);
        self::assertSame(401, $this->api('GET', '/v1/sending-domains', $first['api_key'])->getStatusCode());
        self::assertSame(200, $this->api('GET', '/v1/sending-domains', $second['api_key'])->getStatusCode());
        self::assertSame(['removed', 'enabled'], Db::owner()->fetchFirstColumn('SELECT status FROM webhook_endpoints WHERE client_id = ? ORDER BY created_at, id', [$cid]));
        self::assertSame(['revoked', 'active'], Db::owner()->fetchFirstColumn('SELECT status FROM application_connections WHERE client_id = ? ORDER BY created_at, id', [$cid]));
        self::assertSame('replaced', Db::owner()->fetchOne(
            "SELECT detail_json->>'reason' FROM audit_log WHERE action = 'application_connection.revoked' AND target_id = ?", [$first['connection_id']]));
    }

    public function testAnApplicationDisconnectsItself(): void
    {
        [$client, $admin] = $this->clientWithAdmin('Bye Co');
        $cid = $client->getId()->toRfc4122();
        [$code, $verifier, $id] = $this->approved($client, $admin);
        $credentials = self::json($this->exchange($code, $verifier));
        $own = $this->newKey($client);

        // A key that no connection issued has nothing to disconnect; no key at all is 401.
        $this->assertProblem($this->api('DELETE', '/v1/connection', $own), 404, 'not-found');
        $this->assertProblem($this->api('DELETE', '/v1/connection', null), 401, 'unauthorized');
        self::assertSame('active', Db::owner()->fetchOne('SELECT status FROM application_connections WHERE id = ?', [$id]));

        $response = $this->api('DELETE', '/v1/connection', $credentials['api_key']);
        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('', (string) $response->getContent());
        // Its key is revoked and its endpoint removed; the client's own key is untouched.
        $this->assertProblem($this->api('GET', '/v1/sending-domains', $credentials['api_key']), 401, 'unauthorized');
        $this->assertProblem($this->api('DELETE', '/v1/connection', $credentials['api_key']), 401, 'unauthorized');
        self::assertSame(200, $this->api('GET', '/v1/sending-domains', $own)->getStatusCode());
        self::assertSame('removed', Db::owner()->fetchOne('SELECT status FROM webhook_endpoints WHERE id = ?', [$credentials['webhook']['endpoint_id']]));
        self::assertSame(1, self::rows('SELECT count(*) FROM application_connections WHERE id = ? AND status = ? AND revoked_at IS NOT NULL', [$id, 'revoked']));
        $audit = Db::owner()->fetchAssociative("SELECT actor_type, detail_json->>'reason' AS reason FROM audit_log WHERE action = 'application_connection.revoked' AND target_id = ?", [$id]);
        self::assertSame(['api_key', 'application'], array_values($audit));
        self::assertSame(1, self::rows("SELECT count(*) FROM audit_log WHERE action = 'api_key.revoked' AND actor_type = 'api_key'"
            ." AND target_id = (SELECT api_key_id::text FROM application_connections WHERE id = ?)", [$id]));
        self::assertSame(1, self::rows("SELECT count(*) FROM audit_log WHERE action = 'webhook_endpoint.removed' AND target_id = ?", [$credentials['webhook']['endpoint_id']]));

        // The removed endpoint is gone for the API and the dashboard, cannot be brought back, and counts against no limit.
        $this->assertProblem($this->api('POST', '/v1/webhooks/test', $own, ['webhook_endpoint_id' => $credentials['webhook']['endpoint_id']]), 404, 'not-found');
        $this->signIn($admin);
        $this->fromThisAddress();
        self::assertStringNotContainsString(self::WEBHOOK, $this->crawler("/dashboard/c/$cid/webhooks")->filter('main')->text());
        $this->browser->request('POST', "/dashboard/c/$cid/webhooks/".$credentials['webhook']['endpoint_id'], ['url' => self::WEBHOOK, 'event_types' => ['send.completed'], 'status' => 'enabled',
            '_token' => $this->dashboardToken($cid)]);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode());
        $this->limit($client, ['max_webhook_endpoints' => 1]);
        $this->service(WebhookEndpointService::class)->create($this->reload($client), 'https://lists.example.org/new', ['send.completed'], self::actor());
        self::assertSame('removed', Db::owner()->fetchOne('SELECT status FROM webhook_endpoints WHERE id = ?', [$credentials['webhook']['endpoint_id']]));
    }

    public function testAClientAdminDisconnectsAnApplicationInTheDashboard(): void
    {
        [$client, $admin] = $this->clientWithAdmin('Dash Co');
        $cid = $client->getId()->toRfc4122();
        [$code, $verifier, $id] = $this->approved($client, $admin);
        $credentials = self::json($this->exchange($code, $verifier));
        [$otherClient, $otherAdmin] = $this->clientWithAdmin('Elsewhere Co');
        $revoke = "/dashboard/c/$cid/connections/$id/revoke";

        // A viewer sees the connection but cannot end it; another client's admin sees nothing of it.
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Viewer));
        $this->fromThisAddress();
        $page = $this->crawler("/dashboard/c/$cid/connections");
        self::assertStringContainsString(self::APPLICATION, $page->text());
        self::assertSame(0, $page->filter('form[action="'.$revoke.'"]')->count());
        $this->browser->request('POST', $revoke, ['_token' => 'x']);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode());

        static::ensureKernelShutdown();
        $this->browser = static::createClient(['debug' => false]);
        $this->fromThisAddress();
        $this->signIn($otherAdmin);
        $this->fromThisAddress();
        $oid = $otherClient->getId()->toRfc4122();
        self::assertSame(404, $this->get("/dashboard/c/$cid/connections")->getStatusCode());
        self::assertStringNotContainsString(self::APPLICATION, $this->crawler("/dashboard/c/$oid/connections")->filter('main')->text());
        $this->browser->request('POST', "/dashboard/c/$oid/connections/$id/revoke", ['_token' => $this->dashboardToken($oid)]);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode(), 'a connection of another client does not exist here');
        self::assertSame('active', Db::owner()->fetchOne('SELECT status FROM application_connections WHERE id = ?', [$id]));

        // The client admin ends it: the key stops working at once and the endpoint is removed.
        static::ensureKernelShutdown();
        $this->browser = static::createClient(['debug' => false]);
        $this->fromThisAddress();
        $this->signIn($admin);
        $this->fromThisAddress();
        $response = $this->submit("/dashboard/c/$cid/connections", $revoke);
        self::assertSame(302, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(401, $this->api('GET', '/v1/sending-domains', $credentials['api_key'])->getStatusCode());
        self::assertSame(['revoked', 'removed'], [Db::owner()->fetchOne('SELECT status FROM application_connections WHERE id = ?', [$id]),
            Db::owner()->fetchOne('SELECT status FROM webhook_endpoints WHERE id = ?', [$credentials['webhook']['endpoint_id']])]);
        self::assertSame(['user', $admin->getId()->toRfc4122(), 'client'], array_values(Db::owner()->fetchAssociative(
            "SELECT actor_type, actor_id, detail_json->>'reason' FROM audit_log WHERE action = 'application_connection.revoked' AND target_id = ?", [$id])));
        $page = $this->crawler("/dashboard/c/$cid/connections");
        self::assertStringContainsString('Ended', $page->filter('table')->text());
        self::assertSame(0, $page->filter('form[action="'.$revoke.'"]')->count(), 'an ended connection has no action');
    }

    /** A valid CSRF token of the client's dashboard actions, as its admin has it on any form (here: create an API key). */
    private function dashboardToken(string $clientId): string
    {
        return (string) $this->crawler("/dashboard/c/$clientId/api-keys")->filter('form input[name="_token"]')->first()->attr('value');
    }

    public function testTheExchangeIsRateLimitedPerAddress(): void
    {
        [, $verifier] = self::pkce();
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        for ($i = 0; $i < 30; ++$i) {
            self::assertSame(400, $this->exchange($code, $verifier)->getStatusCode());
        }
        $limited = $this->exchange($code, $verifier);
        $this->assertProblem($limited, 429, 'rate-limited');
        self::assertTrue($limited->headers->has('Retry-After'));
    }
}
