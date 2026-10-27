<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditActor;
use App\Domain\SendingDomainService;
use App\Enum\ClientStatus;
use App\Tests\Support\ApiTestCase;

/**
 * GET /v1/sending-domains (specification 2.12): the authenticated client's own sending domains,
 * their verification and DKIM status, and can_send - the submit rule on the domain as it is now -
 * so that an application can warn before it sends from a domain that cannot send yet.
 */
final class SendingDomainApiTest extends ApiTestCase
{
    public function testAClientReadsItsOwnDomainsAndWhetherEachCanSend(): void
    {
        [$client, $key] = $this->newApiClient();
        $tag = bin2hex(random_bytes(3));
        $verified = $this->verifiedDomain($client, "b-$tag.example");
        $pending = $this->pendingDomain($client, "a-$tag.example");
        $disabled = $this->verifiedDomain($client, "c-$tag.example");
        $this->service(SendingDomainService::class)->disable($this->reload($disabled), AuditActor::system('test'));
        [$other, $otherKey] = $this->newApiClient();
        $this->verifiedDomain($other, "z-$tag.example");

        $response = $this->api('GET', '/v1/sending-domains', $key);
        $body = $this->assertContract($response, 200, '/sending-domains', 'get');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $setup = static fn (object $d): string => 'https://smarthost.localhost/dashboard/c/'.$client->getId()->toRfc4122().'/sending-domains/'.$d->getId()->toRfc4122();
        self::assertSame([
            ['id' => $pending->getId()->toRfc4122(), 'domain' => "a-$tag.example", 'status' => 'pending', 'dkim_status' => 'not_configured', 'verified_at' => null, 'can_send' => false,
                'setup_url' => $setup($pending)],
            ['id' => $verified->getId()->toRfc4122(), 'domain' => "b-$tag.example", 'status' => 'verified', 'dkim_status' => 'not_configured', 'can_send' => true,
                'setup_url' => $setup($verified)],
            ['id' => $disabled->getId()->toRfc4122(), 'domain' => "c-$tag.example", 'status' => 'disabled', 'dkim_status' => 'not_configured', 'can_send' => false,
                'setup_url' => $setup($disabled)],
        ], array_map(static fn (array $d): array => null === $d['verified_at'] ? $d : array_diff_key($d, ['verified_at' => 1]), $body['data']),
            'by domain; only this client\'s');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+02:00$/', $body['data'][1]['verified_at']);

        // The other client sees only its own; a client without domains gets an empty list.
        self::assertSame(["z-$tag.example"], array_column(self::json($this->api('GET', '/v1/sending-domains', $otherKey))['data'], 'domain'));
        [, $emptyKey] = $this->newApiClient();
        self::assertSame(['data' => []], $this->assertContract($this->api('GET', '/v1/sending-domains', $emptyKey), 200, '/sending-domains', 'get'));
    }

    public function testItNeedsAnApiKeyAndIsReadOnlyInEveryStatusThatAuthenticates(): void
    {
        $this->assertProblem($this->api('GET', '/v1/sending-domains', null), 401, 'unauthorized');
        foreach ([ClientStatus::PendingApproval, ClientStatus::Suspended, ClientStatus::Throttled] as $status) {
            [, $key] = $this->newApiClient($status);
            self::assertSame(200, $this->api('GET', '/v1/sending-domains', $key)->getStatusCode(), $status->value);
        }
        [, $closedKey] = $this->newApiClient(ClientStatus::Closed);
        $this->assertProblem($this->api('GET', '/v1/sending-domains', $closedKey), 401, 'unauthorized');
        [, $key] = $this->newApiClient();
        $this->assertProblem($this->api('POST', '/v1/sending-domains', $key, ['domain' => 'new.example']), 405);
        $this->assertProblem($this->api('DELETE', '/v1/sending-domains', $key), 405);
    }

    public function testInProductionADomainSendsOnlyWithActiveDkim(): void
    {
        $rule = new \App\Sending\SendingDomainRule('production', false);
        [$client] = $this->newApiClient();
        $domain = $this->reload($this->verifiedDomain($client));
        self::assertFalse($rule->canSend($domain));
        self::assertSame(['The sending domain has no active DKIM configuration.'], $rule->problems($domain));
        $domain->setDkim(\App\Enum\DkimStatus::Active, 'sel1');
        self::assertTrue($rule->canSend($domain));
        // The local switch never applies in production, and a disabled domain never sends.
        self::assertFalse((new \App\Sending\SendingDomainRule('production', true))->canSend($this->reload($this->pendingDomain($client))));
        self::assertTrue((new \App\Sending\SendingDomainRule('development', true))->canSend($this->reload($this->pendingDomain($client))));
        $domain->disable();
        self::assertFalse((new \App\Sending\SendingDomainRule('development', true))->canSend($domain));
    }
}
