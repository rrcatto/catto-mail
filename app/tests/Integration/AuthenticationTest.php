<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\ApiKey;
use App\Enum\ClientStatus;
use App\Security\ApiKeyManager;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;

/** Machine-client authentication (OpenAPI securitySchemes.apiKey, spec api.authentication). */
final class AuthenticationTest extends ApiTestCase
{
    private const URI = '/v1/validation-jobs/01999999-0000-7000-8000-000000000000';

    public function testValidKeyAuthenticates(): void
    {
        [, $key] = $this->newApiClient();
        $this->assertProblem($this->api('GET', self::URI, $key), 404, 'not-found');
    }

    public function testMissingMalformedAndUnknownKeysAreRejected(): void
    {
        $this->assertProblem($this->api('GET', self::URI, null), 401, 'unauthorized');
        $this->assertProblem($this->api('GET', self::URI, 'not-a-key'), 401, 'unauthorized');
        $this->assertProblem($this->api('GET', self::URI, 'shk_'.str_repeat('A', 43)), 401, 'unauthorized');
        $r = $this->api('GET', self::URI, null, headers: ['Authorization' => 'Basic dXNlcjpwYXNz']);
        $this->assertProblem($r, 401);
        self::assertSame('Bearer', $r->headers->get('WWW-Authenticate'));
    }

    public function testRevokedKeyIsRejected(): void
    {
        [$client, $raw] = $this->newApiClient();
        $this->assertProblem($this->api('GET', self::URI, $raw), 404);
        $key = $this->container()->get('doctrine')->getManager()->getRepository(ApiKey::class)->findOneBy(['keyHash' => ApiKeyManager::hash($raw)]);
        $this->container()->get(ApiKeyManager::class)->revoke($key, self::actor());
        $this->assertProblem($this->api('GET', self::URI, $raw), 401, 'unauthorized');
    }

    public function testKeysOfClosedClientsAreRejected(): void
    {
        [, $raw] = $this->newApiClient(ClientStatus::Closed);
        $this->assertProblem($this->api('GET', self::URI, $raw), 401, 'unauthorized');
    }

    public function testRawKeyIsNeverPersisted(): void
    {
        [$client, $raw] = $this->newApiClient();
        $row = Db::owner()->fetchAssociative('SELECT * FROM api_keys WHERE client_id = ?', [$client->getId()->toRfc4122()]);
        self::assertSame(hash('sha256', $raw), $row['key_hash']);
        self::assertSame(substr($raw, 0, 12), $row['key_prefix']);
        self::assertMatchesRegularExpression('/^shk_[A-Za-z0-9_-]{43}$/', $raw);
        $secret = substr($raw, 12);
        // No column of any table contains the raw key or its secret part.
        foreach (Db::owner()->fetchAllAssociative("SELECT table_name, column_name FROM information_schema.columns
            WHERE table_schema = 'public' AND data_type IN ('text', 'jsonb')") as $col) {
            $hits = (int) Db::owner()->fetchOne(\sprintf('SELECT count(*) FROM %s WHERE %s::text LIKE ?', $col['table_name'], $col['column_name']), ['%'.$secret.'%']);
            self::assertSame(0, $hits, "raw key found in {$col['table_name']}.{$col['column_name']}");
        }
    }

    public function testLastUsedIsTracked(): void
    {
        [$client, $raw] = $this->newApiClient();
        $q = fn () => Db::owner()->fetchOne('SELECT last_used_at FROM api_keys WHERE client_id = ?', [$client->getId()->toRfc4122()]);
        self::assertNull($q());
        $this->api('GET', self::URI, $raw);
        $first = $q();
        self::assertNotNull($first);
        $this->api('GET', self::URI, $raw);
        self::assertSame($first, $q(), 'one-minute granularity: no write on every request');
    }

    public function testApiKeyIsNotADashboardCredential(): void
    {
        [, $raw] = $this->newApiClient();
        $this->browser->request('GET', '/dashboard', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        self::assertStringEndsWith('/dashboard/login', (string) $this->browser->getResponse()->headers->get('Location'));
    }

    public function testFailedAuthenticationIsRateLimitedPerAddress(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            self::assertSame(401, $this->api('GET', self::URI, 'shk_'.str_repeat('B', 43))->getStatusCode());
        }
        $r = $this->api('GET', self::URI, 'shk_'.str_repeat('B', 43));
        $this->assertProblem($r, 429, 'rate-limited');
        self::assertGreaterThan(0, (int) $r->headers->get('Retry-After'));
        // Even a valid key from that address waits until the window passes.
        [, $raw] = $this->newApiClient();
        self::assertSame(429, $this->api('GET', self::URI, $raw)->getStatusCode());
    }

    public function testPerKeyRateLimit(): void
    {
        $this->withEnv(['APP_API_RATE_LIMIT_PER_MINUTE' => '3'], function (): void {
            [, $raw] = $this->newApiClient();
            for ($i = 0; $i < 3; ++$i) {
                self::assertSame(404, $this->api('GET', self::URI, $raw)->getStatusCode());
            }
            $r = $this->api('GET', self::URI, $raw);
            $this->assertProblem($r, 429, 'rate-limited');
            self::assertNotNull($r->headers->get('Retry-After'));
            [, $other] = $this->newApiClient();
            self::assertSame(404, $this->api('GET', self::URI, $other)->getStatusCode(), 'limits are per key');
        });
    }

    public function testUnknownApiRouteIsAProblem(): void
    {
        [, $raw] = $this->newApiClient();
        $this->assertProblem($this->api('GET', '/v1/clients', $raw), 404);
        $this->assertProblem($this->api('DELETE', '/v1/send-jobs/01999999-0000-7000-8000-000000000000', $raw), 405);
    }
}
