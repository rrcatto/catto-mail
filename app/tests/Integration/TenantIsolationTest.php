<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\SendJob;
use App\Entity\ValidationJob;
use App\Tenant\TenantFilter;
use App\Tenant\TenantScope;
use App\Tests\Schema\SchemaFixtures;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

/**
 * Phase 2 acceptance: a client can never see or act on another client's
 * resources, and a foreign resource is indistinguishable from a missing one.
 */
final class TenantIsolationTest extends ApiTestCase
{
    /** @return array{a: string, b: string, bClient: \App\Entity\Client, bValidation: string, bSend: string, bMessage: string, bDomain: \App\Entity\SendingDomain} */
    private function twoTenants(): array
    {
        [, $a] = $this->newApiClient();
        [$bClient, $b] = $this->newApiClient();
        $bDomain = $this->verifiedDomain($bClient);
        $r = $this->api('POST', '/v1/validation-jobs', $b, ['addresses' => [['address' => 'x@example.com']]], ['Idempotency-Key' => self::key()]);
        $bValidation = self::json($r)['id'];
        $bSend = $this->createSendJob($b, $bDomain);
        self::assertSame(201, $this->addBatch($b, $bSend, self::recipients(0, 2))->getStatusCode());
        // A message as the delivery daemon would create it (Phase 4), for the read endpoints.
        $owner = Db::owner();
        $recipient = $owner->fetchOne('SELECT id FROM send_job_recipients WHERE send_job_id = ? ORDER BY id LIMIT 1', [$bSend]);
        $bMessage = SchemaFixtures::message($owner, $bSend, $recipient);
        $owner->insert('message_events', ['id' => SchemaFixtures::id(), 'message_id' => $bMessage, 'event_type' => 'message_created',
            'event_source' => 'delivery_daemon', 'source_event_key' => $bMessage.':created', 'occurred_at' => '2026-10-03T00:00:00Z']);

        return compact('a', 'b', 'bClient', 'bValidation', 'bSend', 'bMessage', 'bDomain');
    }

    public function testClientACannotReadOrOperateClientBResources(): void
    {
        $t = $this->twoTenants();
        $a = $t['a'];
        $before = Db::owner()->fetchAssociative('SELECT status, total_recipients, queued_at FROM send_jobs WHERE id = ?', [$t['bSend']]);

        $this->assertProblem($this->api('GET', "/v1/validation-jobs/{$t['bValidation']}", $a), 404, 'not-found');
        $this->assertProblem($this->api('GET', "/v1/validation-jobs/{$t['bValidation']}/addresses", $a), 404, 'not-found');
        $this->assertProblem($this->api('GET', "/v1/send-jobs/{$t['bSend']}", $a), 404, 'not-found');
        $this->assertProblem($this->api('GET', "/v1/send-jobs/{$t['bSend']}/messages", $a), 404, 'not-found');
        $this->assertProblem($this->addBatch($a, $t['bSend'], self::recipients(10, 1)), 404, 'not-found');
        $this->assertProblem($this->api('POST', "/v1/send-jobs/{$t['bSend']}/submit", $a), 404, 'not-found');
        $this->assertProblem($this->api('GET', "/v1/messages/{$t['bMessage']}/events", $a), 404, 'not-found');

        // B's job is untouched by A's attempts.
        self::assertSame($before, Db::owner()->fetchAssociative('SELECT status, total_recipients, queued_at FROM send_jobs WHERE id = ?', [$t['bSend']]));
        self::assertSame(2, (int) Db::owner()->fetchOne('SELECT count(*) FROM send_job_recipients WHERE send_job_id = ?', [$t['bSend']]));

        // The owner sees everything.
        $b = $t['b'];
        self::assertSame(200, $this->api('GET', "/v1/validation-jobs/{$t['bValidation']}", $b)->getStatusCode());
        $messages = $this->assertContract($this->api('GET', "/v1/send-jobs/{$t['bSend']}/messages", $b), 200, '/send-jobs/{id}/messages', 'get');
        self::assertSame([$t['bMessage']], array_column($messages['data'], 'id'));
        $events = $this->assertContract($this->api('GET', "/v1/messages/{$t['bMessage']}/events", $b), 200, '/messages/{id}/events', 'get');
        self::assertSame(['message_created'], array_column($events['data'], 'event_type'));
    }

    public function testForeignAndMissingResourcesAnswerIdentically(): void
    {
        $t = $this->twoTenants();
        $foreign = $this->api('GET', "/v1/send-jobs/{$t['bSend']}", $t['a']);
        $missing = $this->api('GET', '/v1/send-jobs/'.Uuid::v7()->toRfc4122(), $t['a']);
        $malformed = $this->api('GET', '/v1/send-jobs/not-a-uuid', $t['a']);
        $strip = static fn (array $p): array => array_diff_key($p, ['instance' => 1]);
        self::assertSame($strip(self::json($missing)), $strip(self::json($foreign)));
        self::assertSame($strip(self::json($missing)), $strip(self::json($malformed)));
    }

    public function testClientACannotUseClientBSendingDomain(): void
    {
        $t = $this->twoTenants();
        $r = $this->api('POST', '/v1/send-jobs', $t['a'], ['external_reference' => 'x', 'message_class' => 'transactional',
            'sender_identity' => ['email' => 'news@'.$t['bDomain']->getDomain()]], ['Idempotency-Key' => self::key()]);
        $problem = $this->assertProblem($r, 422, 'validation-error');
        self::assertSame('/sender_identity/email', $problem['errors'][0]['pointer']);
    }

    public function testClientIdInRequestBodiesIsRejected(): void
    {
        $t = $this->twoTenants();
        $r = $this->api('POST', '/v1/validation-jobs', $t['a'], ['client_id' => $t['bClient']->getId()->toRfc4122(),
            'addresses' => [['address' => 'x@example.com']]], ['Idempotency-Key' => self::key()]);
        $problem = $this->assertProblem($r, 422, 'validation-error');
        self::assertContains('/client_id', array_column($problem['errors'], 'pointer'));
    }

    public function testIdempotencyKeysAreScopedPerClient(): void
    {
        [, $a] = $this->newApiClient();
        [, $b] = $this->newApiClient();
        $key = self::key();
        $body = ['addresses' => [['address' => 'same@example.com']]];
        $ra = self::json($this->api('POST', '/v1/validation-jobs', $a, $body, ['Idempotency-Key' => $key]));
        $rb = $this->api('POST', '/v1/validation-jobs', $b, $body, ['Idempotency-Key' => $key]);
        self::assertSame(202, $rb->getStatusCode());
        self::assertNull($rb->headers->get('Idempotent-Replayed'), 'another client never replays a foreign job');
        self::assertNotSame($ra['id'], self::json($rb)['id']);
    }

    public function testTenantScopeServiceAndDoctrineFilter(): void
    {
        $t = $this->twoTenants();
        [$aClient, $aKey] = $this->newApiClient();
        $aDomain = $this->verifiedDomain($aClient);
        $container = $this->container();
        $user = new \App\Security\ApiClientUser($container->get('doctrine')->getManager()->getRepository(\App\Entity\ApiKey::class)
            ->findOneBy(['keyHash' => \App\Security\ApiKeyManager::hash($aKey)]));
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));

        /** @var TenantScope $scope */
        $scope = $container->get(TenantScope::class);
        self::assertNull($scope->sendingDomain($t['bDomain']->getId()->toRfc4122()));
        self::assertNotNull($scope->sendingDomain($aDomain->getId()->toRfc4122()));
        self::assertNull($scope->sendJob($t['bSend']));
        self::assertNull($scope->validationJob($t['bValidation']));
        self::assertNull($scope->message($t['bMessage']));

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $em->clear();
        $em->getFilters()->enable('tenant')->setParameter(TenantFilter::PARAMETER, $aClient->getId()->toRfc4122(), 'string');
        self::assertNull($em->find(SendJob::class, Uuid::fromString($t['bSend'])), 'even a raw find() cannot load a foreign job');
        self::assertNull($em->find(ValidationJob::class, Uuid::fromString($t['bValidation'])));
        self::assertSame([], $em->getRepository(\App\Entity\Message::class)->findAll());
        self::assertSame([], $em->getRepository(\App\Entity\User::class)->findAll(), 'operator-only tables are invisible to API scope');
        self::assertSame([], $em->getRepository(\App\Entity\AuditLogEntry::class)->findAll());
        self::assertCount(1, $em->getRepository(\App\Entity\Client::class)->findAll());
        self::assertSame([$aDomain->getId()->toRfc4122()], array_map(fn ($d) => $d->getId()->toRfc4122(), $em->getRepository(\App\Entity\SendingDomain::class)->findAll()));
        $em->getFilters()->disable('tenant');
    }

    public function testOneKernelServingTwoClientsDoesNotCarryTheFilterOver(): void
    {
        [, $a] = $this->newApiClient();
        [, $b] = $this->newApiClient();
        $this->browser->disableReboot(); // same kernel and entity manager for both requests
        self::assertSame(404, $this->api('GET', '/v1/send-jobs/01999999-0000-7000-8000-000000000000', $a)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/v1/send-jobs/01999999-0000-7000-8000-000000000000', $b)->getStatusCode(),
            'client B must authenticate although client A filter was active in this kernel');
    }

    public function testWebhookEndpointsAreTenantScoped(): void
    {
        [$aClient, $aKey] = $this->newApiClient();
        $b = $this->newClient();
        $service = $this->container()->get(\App\Webhook\WebhookEndpointService::class);
        [$bEndpoint] = $service->create($this->reload($b), 'https://hooks.example/b', ['send.completed'], self::actor());
        $container = $this->container();
        $user = new \App\Security\ApiClientUser($container->get('doctrine')->getManager()->getRepository(\App\Entity\ApiKey::class)
            ->findOneBy(['keyHash' => \App\Security\ApiKeyManager::hash($aKey)]));
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));
        self::assertNull($container->get(TenantScope::class)->webhookEndpoint($bEndpoint->getId()->toRfc4122()));
        // Another client's webhook endpoint is "not found" for the test endpoint.
        $this->assertProblem($this->api('POST', '/v1/webhooks/test', $aKey, ['webhook_endpoint_id' => $bEndpoint->getId()->toRfc4122()]), 404, 'not-found');
    }
}
