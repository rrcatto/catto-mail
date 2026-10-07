<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Client\ClientLimitAdministration;
use App\Client\ClientLimitPolicy;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Enum\ClientStatus;
use App\Security\ApiKeyManager;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;

/**
 * Phase 9 per-client limits and quotas (specification 2.10 client_limits): quotas
 * enforced at the operation boundary with 429 quota-exceeded (contract shape,
 * Retry-After), never counting idempotent replays, concurrency-safe across processes;
 * the per-job recipient limit; structural limits (keys, domains, endpoints); the
 * per-client API rate; ceilings that a client limit can only lower.
 */
final class QuotaTest extends ApiTestCase
{
    /** @param array<string, ?int> $limits */
    private function limit(Client $client, array $limits): void
    {
        $this->container()->get(ClientLimitAdministration::class)->set($this->reload($client), $limits, self::actor(), 'test limits');
    }

    private function validation(string $key, int $addresses = 1, ?string $idem = null): \Symfony\Component\HttpFoundation\Response
    {
        $list = [];
        for ($i = 0; $i < $addresses; ++$i) {
            $list[] = ['address' => "q$i@example.com"];
        }

        return $this->api('POST', '/v1/validation-jobs', $key, ['addresses' => $list], ['Idempotency-Key' => $idem ?? self::key()]);
    }

    public function testDailyValidationQuotasAreEnforcedAndReplaysDoNotCount(): void
    {
        [$client, $key] = $this->newApiClient();
        $this->limit($client, ['validation_jobs_per_day' => 2, 'validation_addresses_per_day' => 5]);
        $idem = self::key();
        self::assertSame(202, $this->validation($key, 2, $idem)->getStatusCode());
        self::assertSame(202, $this->validation($key, 2, $idem)->getStatusCode(), 'replay');
        // 2 addresses used: 4 more would exceed 5.
        $problem = $this->assertContract($this->validation($key, 4), 429, '/validation-jobs', 'post');
        self::assertStringEndsWith('/problems/quota-exceeded', $problem['type']);
        self::assertSame(['metric' => 'validation_addresses', 'period' => 'day', 'limit' => 5, 'used' => 2, 'requested' => 4],
            array_intersect_key($problem['quota'], array_flip(['metric', 'period', 'limit', 'used', 'requested'])));
        self::assertStringStartsWith(gmdate('Y-m-d', strtotime('+1 day')).'T00:00:00', $problem['quota']['resets_at']);
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM validation_jobs WHERE client_id = ?', [$client->getId()->toRfc4122()]), 'nothing created');
        self::assertSame(202, $this->validation($key, 3)->getStatusCode());
        $r = $this->validation($key, 1);
        $this->assertContract($r, 429, '/validation-jobs', 'post');
        self::assertSame('validation_jobs', self::json($r)['quota']['metric']);
        self::assertGreaterThan(0, (int) $r->headers->get('Retry-After'));
        self::assertLessThanOrEqual(86400, (int) $r->headers->get('Retry-After'));
        $used = Db::owner()->fetchAllKeyValue("SELECT metric || '|' || period, used FROM client_quota_usage WHERE client_id = ? ORDER BY 1", [$client->getId()->toRfc4122()]);
        self::assertSame(['validation_addresses|day' => 5, 'validation_addresses|month' => 5, 'validation_jobs|day' => 2, 'validation_jobs|month' => 2], array_map('intval', $used));
    }

    public function testSendQuotasAndTheRecipientLimitPerJob(): void
    {
        [$client, $key] = $this->newApiClient();
        $domain = $this->verifiedDomain($client);
        $this->limit($client, ['send_jobs_per_day' => 2, 'send_recipients_per_month' => 6, 'max_recipients_per_send_job' => 4]);
        $job = $this->createSendJob($key, $domain);
        $p = $this->assertProblem($this->addBatch($key, $job, self::recipients(0, 5)), 422, 'recipient-limit-exceeded');
        self::assertStringContainsString('at most 4 recipients', $p['detail']);
        self::assertSame(201, $this->addBatch($key, $job, self::recipients(0, 4))->getStatusCode());
        $second = $this->createSendJob($key, $domain);
        $q = $this->assertContract($this->addBatch($key, $second, self::recipients(10, 3)), 429, '/send-jobs/{id}/recipients', 'post');
        self::assertSame(['send_recipients', 'month', 6, 4, 3], [$q['quota']['metric'], $q['quota']['period'], $q['quota']['limit'], $q['quota']['used'], $q['quota']['requested']]);
        self::assertSame(201, $this->addBatch($key, $second, self::recipients(10, 2))->getStatusCode());
        $r = $this->api('POST', '/v1/send-jobs', $key, ['external_reference' => 'x', 'message_class' => 'transactional',
            'sender_identity' => ['email' => 'a@'.$domain->getDomain()]], ['Idempotency-Key' => self::key()]);
        self::assertSame('send_jobs', $this->assertContract($r, 429, '/send-jobs', 'post')['quota']['metric']);
    }

    public function testConcurrentRequestsCannotBypassAQuota(): void
    {
        [$client, $key] = $this->newApiClient();
        $this->limit($client, ['validation_jobs_per_day' => 3]);
        $results = $this->concurrent(8, $key, '/v1/validation-jobs', ['addresses' => [['address' => 'c@example.com']]]);
        $statuses = array_count_values(array_column($results, 'status'));
        ksort($statuses);
        self::assertSame([202 => 3, 429 => 5], $statuses);
        self::assertSame(3, (int) Db::owner()->fetchOne('SELECT count(*) FROM validation_jobs WHERE client_id = ?', [$client->getId()->toRfc4122()]));
        self::assertSame(3, (int) Db::owner()->fetchOne("SELECT used FROM client_quota_usage WHERE client_id = ? AND metric = 'validation_jobs' AND period = 'day'",
            [$client->getId()->toRfc4122()]), 'refused requests left no count');
    }

    public function testStructuralLimitsAndCeilings(): void
    {
        $client = $this->newClient();
        /** @var ApiKeyManager $keys */
        $keys = $this->service(ApiKeyManager::class);
        try {
            $this->limit($client, ['max_api_keys' => 11]);
            self::fail('a client limit cannot raise the ceiling (APP_CLIENT_API_KEY_LIMIT=10)');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('ceiling', $e->getMessage());
        }
        $this->limit($client, ['max_api_keys' => 2, 'max_sending_domains' => 1, 'max_webhook_endpoints' => 1]);
        [$first] = $keys->create($this->reload($client), 'one', self::actor());
        $keys->create($this->reload($client), 'two', self::actor(), new \DateTimeImmutable('+30 days'));
        try {
            $keys->create($this->reload($client), 'three', self::actor());
            self::fail('key limit');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('2 usable API keys', $e->getMessage());
        }
        $keys->revoke($this->reload($first), self::actor());
        $keys->create($this->reload($client), 'replacement', self::actor());
        $this->verifiedDomain($client);
        try {
            $this->verifiedDomain($client);
            self::fail('domain limit');
        } catch (DomainRuleViolation) {
        }
        $endpoints = $this->service(\App\Webhook\WebhookEndpointService::class);
        $endpoints->create($this->reload($client), 'https://hooks.example/1', ['send.completed'], self::actor());
        try {
            $endpoints->create($this->reload($client), 'https://hooks.example/2', ['send.completed'], self::actor());
            self::fail('endpoint limit');
        } catch (DomainRuleViolation) {
        }
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'client.limits_changed' AND target_id = ?", [$client->getId()->toRfc4122()]));
    }

    public function testExpiredKeysFailLikeRevokedOnes(): void
    {
        [$client, $key] = $this->newApiClient();
        self::assertSame(404, $this->api('GET', '/v1/send-jobs/01999999-0000-7000-8000-000000000000', $key)->getStatusCode());
        Db::owner()->executeStatement("UPDATE api_keys SET created_at = now() - interval '2 days', expires_at = now() - interval '1 second' WHERE client_id = ?", [$client->getId()->toRfc4122()]);
        $this->assertProblem($this->api('GET', '/v1/send-jobs/01999999-0000-7000-8000-000000000000', $key), 401, 'unauthorized');
    }

    public function testPerClientApiRateAcrossKeysAndThrottling(): void
    {
        [$client, $key] = $this->newApiClient();
        $other = $this->newKey($client);
        $this->limit($client, ['api_requests_per_minute' => 3]);
        $uri = '/v1/send-jobs/01999999-0000-7000-8000-000000000000';
        self::assertSame([404, 404, 404], [$this->api('GET', $uri, $key)->getStatusCode(), $this->api('GET', $uri, $other)->getStatusCode(),
            $this->api('GET', $uri, $key)->getStatusCode()]);
        $r = $this->api('GET', $uri, $other);
        $this->assertProblem($r, 429, 'rate-limited');
        self::assertNotNull($r->headers->get('Retry-After'));

        /** @var ClientLimitPolicy $policy */
        $policy = $this->service(ClientLimitPolicy::class);
        $fresh = $this->newClient();
        self::assertSame(100000, $policy->apiRequestsPerMinute($this->reload($fresh)), 'the installation ceiling (test: 100000)');
        $this->setClientStatus($fresh, ClientStatus::Throttled);
        self::assertSame(60, $policy->apiRequestsPerMinute($this->reload($fresh)), 'APP_THROTTLED_CLIENT_API_RATE_PER_MINUTE');
    }

    /** @return list<array{status: int, replayed: ?string, body: mixed}> */
    private function concurrent(int $n, string $apiKey, string $uri, array $body): array
    {
        $spec = tempnam(sys_get_temp_dir(), 'req');
        file_put_contents($spec, json_encode(['key' => $apiKey, 'method' => 'POST', 'uri' => $uri, 'ip' => '10.201.0.1',
            'idempotency_key' => 'unique', 'body' => json_encode($body)]));
        $start = (string) (microtime(true) + 3.0);
        $procs = [];
        $pipes = [];
        for ($i = 0; $i < $n; ++$i) {
            $procs[] = proc_open(['php', \dirname(__DIR__).'/bin/request.php', $spec, $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        $out = [];
        foreach ($procs as $i => $p) {
            $stdout = (string) stream_get_contents($pipes[$i][1]);
            $stderr = (string) stream_get_contents($pipes[$i][2]);
            proc_close($p);
            self::assertNotSame('', trim($stdout), "worker $i failed: $stderr");
            $lines = explode("\n", trim($stdout));
            $out[] = json_decode((string) end($lines), true, 512, \JSON_THROW_ON_ERROR);
        }
        unlink($spec);

        return $out;
    }
}
