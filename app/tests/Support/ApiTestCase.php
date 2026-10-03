<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Api\OpenApiContract;
use App\Audit\AuditActor;
use App\Client\AccountAdministration;
use App\Domain\SendingDomainService;
use App\Entity\Client;
use App\Entity\SendingDomain;
use App\Enum\ClientStatus;
use App\Security\ApiKeyManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests through the real HTTP kernel, the real firewall and the
 * least-privilege application database role. Every test creates its own
 * clients, so tests are independent without deleting anything.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $browser;
    private string $ip;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        // A distinct address per test keeps the per-IP failed-authentication limiter independent.
        $this->ip = '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    /**
     * The kernel's container, cleaned after HTTP requests: a request that failed
     * inside a transaction leaves the entity manager closed, and an authenticated
     * API request leaves the tenant filter enabled for its client.
     */
    protected function container(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        $container = static::getContainer();
        $doctrine = $container->get('doctrine');
        if (!$doctrine->getManager()->isOpen()) {
            $doctrine->resetManager();
        }
        $filters = $doctrine->getManager()->getFilters();
        if ($filters->isEnabled('tenant')) {
            $filters->disable('tenant');
        }

        return $container;
    }

    protected function service(string $id): object
    {
        return $this->container()->get($id);
    }

    protected static function actor(): AuditActor
    {
        return AuditActor::system('test');
    }

    protected function newClient(ClientStatus $status = ClientStatus::Active, string $name = 'Test client'): Client
    {
        /** @var AccountAdministration $accounts */
        $accounts = $this->service(AccountAdministration::class);

        return $accounts->createClient($name.' '.bin2hex(random_bytes(4)), 'ops@example.test', 'test', $status, self::actor());
    }

    /** @return array{0: Client, 1: string} a client with a fresh API key */
    protected function newApiClient(ClientStatus $status = ClientStatus::Active): array
    {
        $client = $this->newClient($status);

        return [$client, $this->newKey($client)];
    }

    protected function newKey(Client $client): string
    {
        /** @var ApiKeyManager $keys */
        $keys = $this->service(ApiKeyManager::class);

        return $keys->create($this->reload($client), 'test', self::actor())[1];
    }

    protected function verifiedDomain(Client $client, ?string $domain = null): SendingDomain
    {
        /** @var SendingDomainService $domains */
        $domains = $this->service(SendingDomainService::class);
        $d = $domains->register($this->reload($client), $domain ?? 'mail-'.bin2hex(random_bytes(4)).'.example', self::actor());
        $domains->markVerifiedWithoutDns($d, self::actor(), 'test');

        return $d;
    }

    protected function pendingDomain(Client $client, ?string $domain = null): SendingDomain
    {
        /** @var SendingDomainService $domains */
        $domains = $this->service(SendingDomainService::class);

        return $domains->register($this->reload($client), $domain ?? 'mail-'.bin2hex(random_bytes(4)).'.example', self::actor());
    }

    /** @template T of object @param T $entity @return T */
    protected function reload(object $entity): object
    {
        return $this->container()->get('doctrine')->getManager()->find($entity::class, $entity->getId());
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string>            $headers
     */
    protected function api(string $method, string $uri, ?string $key, array|string|null $body = null, array $headers = []): Response
    {
        $server = ['REMOTE_ADDR' => $this->ip, 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $key) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$key;
        }
        if (null !== $body) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        foreach ($headers as $name => $value) {
            $normalized = strtoupper(str_replace('-', '_', $name));
            $server['CONTENT_TYPE' === $normalized ? 'CONTENT_TYPE' : 'HTTP_'.$normalized] = $value;
        }
        $content = \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES) : $body;
        $this->browser->request($method, $uri, server: $server, content: $content);

        return $this->browser->getResponse();
    }

    /** @return array<string, mixed> */
    protected static function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Asserts the status and that the body conforms to the OpenAPI response schema
     * of the operation (or to Problem for application/problem+json).
     */
    protected function assertContract(Response $response, int $status, string $pathTemplate, string $method): array
    {
        $body = (string) $response->getContent();
        self::assertSame($status, $response->getStatusCode(), "Unexpected status; body: $body");
        $contract = $this->service(OpenApiContract::class);
        if ($status >= 400) {
            self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
            $pointer = '#/components/schemas/Problem';
        } else {
            self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
            $pointer = $contract->responseSchemaPointer($pathTemplate, $method, $status);
            self::assertNotNull($pointer, "No response schema in the contract for $method $pathTemplate $status");
        }
        $errors = $contract->validate(json_decode($body, false, 512, \JSON_THROW_ON_ERROR), $pointer);
        self::assertSame([], $errors, 'Response violates the OpenAPI contract: '.json_encode($errors));

        return self::json($response);
    }

    /** Asserts an RFC 9457 problem with the given status and slug. */
    protected function assertProblem(Response $response, int $status, ?string $slug = null): array
    {
        $problem = $this->assertContract($response, $status, '', '');
        if (null !== $slug) {
            self::assertSame('https://smarthost.localhost/problems/'.$slug, $problem['type'], json_encode($problem));
        }
        self::assertSame($status, $problem['status']);

        return $problem;
    }

    /** @return array<string, mixed> a minimal valid recipient */
    protected static function recipient(int $i, array $overrides = []): array
    {
        return $overrides + [
            'external_recipient_reference' => "r-$i",
            'email_address' => "user$i@example.com",
            'subject' => "Hello $i",
            'text_body' => "Body for recipient $i",
        ];
    }

    /** @return list<array<string, mixed>> */
    protected static function recipients(int $from, int $count, array $overrides = []): array
    {
        $out = [];
        for ($i = $from; $i < $from + $count; ++$i) {
            $out[] = self::recipient($i, $overrides);
        }

        return $out;
    }

    protected static function key(): string
    {
        return 'test-'.bin2hex(random_bytes(12));
    }

    /** Creates a transactional (or subscription) send job and returns its id. */
    protected function createSendJob(string $apiKey, SendingDomain $domain, string $class = 'transactional', array $extra = []): string
    {
        $body = $extra + ['external_reference' => 'job-'.bin2hex(random_bytes(3)), 'message_class' => $class,
            'sender_identity' => ['email' => 'news@'.$domain->getDomain(), 'name' => 'Sender']];
        if ('subscription' === $class && !\array_key_exists('list_id', $extra)) {
            $body['list_id'] = 'newsletter.example';
        }
        $r = $this->api('POST', '/v1/send-jobs', $apiKey, $body, ['Idempotency-Key' => self::key()]);
        self::assertSame(201, $r->getStatusCode(), (string) $r->getContent());

        return self::json($r)['id'];
    }

    protected function addBatch(string $apiKey, string $jobId, array $recipients, ?string $idempotencyKey = null): Response
    {
        return $this->api('POST', "/v1/send-jobs/$jobId/recipients", $apiKey, ['recipients' => $recipients],
            ['Idempotency-Key' => $idempotencyKey ?? self::key()]);
    }

    /** Temporarily overrides a contract variable (resolved by the container at runtime). */
    protected function withEnv(array $vars, callable $fn): mixed
    {
        $old = [];
        foreach ($vars as $k => $v) {
            $old[$k] = $_SERVER[$k] ?? null;
            $_SERVER[$k] = $_ENV[$k] = $v;
            putenv("$k=$v");
        }
        try {
            static::ensureKernelShutdown();
            $this->browser = static::createClient();

            return $fn();
        } finally {
            foreach ($old as $k => $v) {
                if (null === $v) {
                    unset($_SERVER[$k], $_ENV[$k]);
                    putenv($k);
                } else {
                    $_SERVER[$k] = $_ENV[$k] = $v;
                    putenv("$k=$v");
                }
            }
            static::ensureKernelShutdown();
            $this->browser = static::createClient();
        }
    }
}
