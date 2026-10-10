<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditActor;
use App\Client\ClientLimitAdministration;
use App\Enum\ClientMembershipRole;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use App\Tests\Support\Phase9Fixtures;
use App\Usage\BillingStatementService;
use App\Usage\UsagePeriod;

/**
 * Phase 9 tenant-isolation review with two deterministic clients A and B, through
 * both the public API and the browser: every Phase 9 datum (limits and quota usage, API
 * keys, usage, billing statements, policy acceptance, account details, private notes,
 * reputation, alerts) and the earlier resources (validation jobs and results, send jobs,
 * messages and events, suppressions, sending domains, webhook endpoints and deliveries)
 * stay with their client. Foreign client pages answer 404 like missing ones; operator
 * pages answer 403 to client users; quota problems disclose only the client's own figures.
 */
final class Phase9TenantIsolationTest extends DashboardTestCase
{
    /** @return array<string, mixed> */
    private function twoClients(): array
    {
        [$a, $aKey] = $this->newApiClient();
        [$b, $bKey] = $this->newApiClient();
        $o = Db::owner();
        $last = (new \DateTimeImmutable('first day of last month 12:00', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $bId = $b->getId()->toRfc4122();
        $bDomain = $this->verifiedDomain($b);
        $bValidation = Phase9Fixtures::meteredValidationJob($o, $bId, 20, $last);
        $bSend = Phase9Fixtures::meteredSendJob($o, $bId, $bDomain->getId()->toRfc4122(), 3, $last);
        $this->container()->get(ClientLimitAdministration::class)->set($this->reload($b), ['send_recipients_per_day' => 777, 'validation_jobs_per_day' => 1], self::actor(), 'B plan');
        $this->container()->get(\App\Client\ClientLifecycle::class)->addNote($this->reload($b), 'B private operator note', self::actor());
        $statement = $this->container()->get(BillingStatementService::class)->prepare($this->reload($b), UsagePeriod::named('previous_month', $this->container()->get(\App\Util\InstallationTime::class)->zone), AuditActor::system('test'));
        $this->container()->get(BillingStatementService::class)->finalize($this->reload($statement), AuditActor::system('test'));
        $bEndpoint = $this->container()->get(\App\Webhook\WebhookEndpointService::class)->create($this->reload($b), 'https://hooks.b.example/x', ['send.completed'], self::actor())[0];
        $bKeyId = $o->fetchOne('SELECT id FROM api_keys WHERE client_id = ?', [$bId]);

        return ['a' => $a, 'aKey' => $aKey, 'b' => $b, 'bKey' => $bKey, 'bId' => $bId, 'bValidation' => $bValidation, 'bSend' => $bSend,
            'bKeyId' => $bKeyId, 'bEndpoint' => $bEndpoint->getId()->toRfc4122(), 'bDomain' => $bDomain];
    }

    public function testApiKeepsEveryResourceAndQuotaWithItsClient(): void
    {
        $t = $this->twoClients();
        $a = $t['aKey'];
        foreach (["/v1/validation-jobs/{$t['bValidation']}", "/v1/validation-jobs/{$t['bValidation']}/addresses", "/v1/send-jobs/{$t['bSend']['job']}",
            "/v1/send-jobs/{$t['bSend']['job']}/messages", "/v1/messages/{$t['bSend']['messages'][0]}/events"] as $uri) {
            $this->assertProblem($this->api('GET', $uri, $a), 404, 'not-found');
        }
        $this->assertProblem($this->api('POST', '/v1/webhooks/test', $a, ['webhook_endpoint_id' => $t['bEndpoint']]), 404, 'not-found');
        $r = $this->api('POST', '/v1/send-jobs', $a, ['external_reference' => 'x', 'message_class' => 'transactional',
            'sender_identity' => ['email' => 'a@'.$t['bDomain']->getDomain()]], ['Idempotency-Key' => self::key()]);
        $this->assertProblem($r, 422);

        // B's quota (1 validation job per day) is B's alone: A is not limited by it, and B's refusal shows only B's figures.
        for ($i = 0; $i < 2; ++$i) {
            self::assertSame(202, $this->api('POST', '/v1/validation-jobs', $a, ['addresses' => [['address' => "a$i@example.com"]]], ['Idempotency-Key' => self::key()])->getStatusCode());
        }
        self::assertSame(202, $this->api('POST', '/v1/validation-jobs', $t['bKey'], ['addresses' => [['address' => 'b@example.com']]], ['Idempotency-Key' => self::key()])->getStatusCode());
        $p = $this->assertProblem($this->api('POST', '/v1/validation-jobs', $t['bKey'], ['addresses' => [['address' => 'b2@example.com']]], ['Idempotency-Key' => self::key()]), 429, 'quota-exceeded');
        self::assertSame(['metric' => 'validation_jobs', 'period' => 'day', 'limit' => 1, 'used' => 1, 'requested' => 1],
            array_intersect_key($p['quota'], array_flip(['metric', 'period', 'limit', 'used', 'requested'])));
        self::assertStringNotContainsString($t['a']->getId()->toRfc4122(), json_encode($p));
    }

    public function testDashboardPagesOfAnotherClientAre404(): void
    {
        $t = $this->twoClients();
        $bId = $t['bId'];
        $admin = $this->newUser(false, $t['a'], ClientMembershipRole::Admin);
        $this->signIn($admin);
        $aId = $t['a']->getId()->toRfc4122();
        foreach (['', '/api-keys', '/usage', '/webhooks', '/sending-domains', '/suppressions', '/validation-jobs', '/send-jobs',
            "/validation-jobs/{$t['bValidation']}", "/send-jobs/{$t['bSend']['job']}", "/messages/{$t['bSend']['messages'][0]}"] as $suffix) {
            self::assertSame(404, $this->page("/dashboard/c/$bId$suffix")->getStatusCode(), "B$suffix");
        }
        foreach (['/api-keys' => [], "/api-keys/{$t['bKeyId']}/revoke" => [], '/policy/accept' => ['policy_version' => 'aup-test-1'],
            "/webhooks/{$t['bEndpoint']}/rotate" => []] as $suffix => $fields) {
            $this->browser->request('POST', "/dashboard/c/$bId$suffix", $fields + ['_token' => 'x']);
            self::assertSame(404, $this->browser->getResponse()->getStatusCode(), "POST B$suffix");
        }
        // B's key cannot be revoked through A's URL either.
        $this->browser->request('POST', "/dashboard/c/$aId/api-keys/{$t['bKeyId']}/revoke", ['_token' => $this->crawler("/dashboard/c/$aId/api-keys")->filter('input[name="_token"]')->first()->attr('value')]);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode());
        self::assertNull(Db::owner()->fetchOne('SELECT revoked_at FROM api_keys WHERE id = ?', [$t['bKeyId']]));

        // A's own pages never show B's data.
        $html = '';
        foreach (['', '/api-keys', '/usage', '/webhooks'] as $suffix) {
            $html .= (string) $this->page("/dashboard/c/$aId$suffix")->getContent();
        }
        foreach ([$bId, 'B private operator note', 'hooks.b.example', $t['bKeyId']] as $needle) {
            self::assertStringNotContainsString($needle, $html, $needle);
        }
        // Operator pages (including notes, reputation, usage of every client) are not for client users.
        foreach (["/dashboard/operator/clients/$bId", "/dashboard/operator/clients/$aId", '/dashboard/operator/alerts', '/dashboard/operator/usage'] as $uri) {
            self::assertSame(403, $this->page($uri)->getStatusCode(), $uri);
        }
        $this->browser->request('POST', "/dashboard/operator/usage/clients/$bId/export", ['_token' => 'x', 'format' => 'json']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
    }

    public function testClientsSeeOnlyTheirFinalizedStatementsAndNoOperatorData(): void
    {
        $t = $this->twoClients();
        $viewer = $this->newUser(false, $t['b'], ClientMembershipRole::Viewer);
        $this->signIn($viewer);
        $bId = $t['bId'];
        $usage = self::text($this->page("/dashboard/c/$bId/usage"));
        self::assertStringContainsString('Finalized', $usage, 'B sees its own finalized statement');
        self::assertStringContainsString('777', $usage, 'and its own limits');
        $overview = (string) $this->page("/dashboard/c/$bId")->getContent();
        foreach (['B private operator note', 'Reputation', 'reconciliation'] as $needle) {
            self::assertStringNotContainsString($needle, $overview.$usage, $needle);
        }
    }
}
