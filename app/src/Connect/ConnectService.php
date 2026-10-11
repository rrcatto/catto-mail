<?php

declare(strict_types=1);

namespace App\Connect;

use App\Api\Presenter;
use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Client\ClientLimitPolicy;
use App\Dashboard\Labels;
use App\Domain\DomainRuleViolation;
use App\Entity\ApiKey;
use App\Entity\ApplicationConnection;
use App\Entity\Client;
use App\Entity\ClientMembership;
use App\Entity\SendingDomain;
use App\Entity\User;
use App\Enum\ApplicationConnectionStatus;
use App\Enum\ClientMembershipRole;
use App\Enum\ClientStatus;
use App\Security\ApiKeyManager;
use App\Sending\SendingDomainRule;
use App\Util\Clock;
use App\Webhook\WebhookEndpointService;
use App\Webhook\WebhookTargetGuard;
use App\Webhook\WebhookTargetRefused;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Connecting an application with one approval instead of a copied API key and webhook secret
 * (specification 2.12): an authorisation-code flow with PKCE.
 *
 *   request()   what the application asked for on the connect page, validated: the syntax
 *               (App\Connect\ConnectRequest) and the webhook URL through the endpoint rule and
 *               the webhook target guard. Nothing is stored.
 *   approve()   a client admin approves for one of their active clients. Refused when the
 *               client's API-key or webhook-endpoint limit leaves no room. Stores the
 *               connection as pending with the SHA-256 of a single-use code (CODE_TTL_SECONDS)
 *               and the application's code challenge; the raw code is returned once, for the
 *               redirect back to the application. Audited.
 *   exchange()  the application presents the code, its code verifier and the redirect URI,
 *               server to server. In one transaction the connection becomes active, an earlier
 *               connection of the client to the same webhook URL is ended, and the API key
 *               (named after the application) and the webhook endpoint (EVENTS) are created.
 *               The raw key and the signing secret exist only in the returned credentials: an
 *               API key is never stored, so it cannot be created before the application is
 *               there to receive it. A used code is never accepted again. Audited.
 *   revoke()    ends a connection: the key is revoked and the endpoint removed. By the
 *               application itself (DELETE /v1/connection) or a client admin. Audited.
 *
 * The global-opt-out capability (clients.can_submit_global_suppressions) is never touched
 * here: only an operator grants it. The code, the code verifier, the API key and the signing
 * secret are never logged or audited.
 */
final class ConnectService
{
    public const CODE_TTL_SECONDS = 600;
    /** What the application's webhook endpoint is subscribed to. */
    public const EVENTS = ['validation.completed', 'validation.failed', 'send.completed', 'send.failed', 'message.hard_bounced', 'message.complained'];
    public const ACTOR = 'application-connect';
    private const CODE_PATTERN = '/^[A-Za-z0-9_-]{43}$/';
    /** RFC 7636: 43 to 128 unreserved characters. */
    private const VERIFIER_PATTERN = '/^[A-Za-z0-9._~-]{43,128}$/';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly ApiKeyManager $keys,
        private readonly WebhookEndpointService $endpoints,
        private readonly WebhookTargetGuard $guard,
        private readonly ClientLimitPolicy $limits,
        private readonly SendingDomainRule $domainRule,
        private readonly AuditLogger $audit,
        private readonly string $smarthostEnv,
        private readonly string $publicBaseUrl,
    ) {
    }

    /**
     * @param array<array-key, mixed> $input the query of the connect page, or the fields of its form
     *
     * @throws DomainRuleViolation naming what is missing or refused
     */
    public function request(array $input): ConnectRequest
    {
        $request = ConnectRequest::fromArray($input, \in_array($this->smarthostEnv, ['development', 'test'], true));
        try {
            $this->endpoints->assertUrl($request->webhookUrl);
            $this->guard->check($request->webhookUrl);
        } catch (DomainRuleViolation|WebhookTargetRefused $e) {
            throw new DomainRuleViolation('webhook_url was refused: '.$e->getMessage());
        } catch (\RuntimeException) {
            throw new DomainRuleViolation('webhook_url was refused: its host could not be resolved.');
        }

        return $request;
    }

    /**
     * The person's workspaces for the approval page, by name: each with why it cannot be chosen
     * (null: it can) and the active connection the approval would replace.
     *
     * @return list<array{client: Client, obstacle: ?string, replaces: ?array{application_name: string, created_at: string}}>
     */
    public function workspaces(User $user, ConnectRequest $request): array
    {
        $out = [];
        foreach ($this->em->createQuery('SELECT m, c FROM App\Entity\ClientMembership m JOIN m.client c WHERE m.user = :u ORDER BY c.companyName')
            ->setParameter('u', $user->getId(), 'uuid')->getResult() as $membership) {
            /** @var ClientMembership $membership */
            $client = $membership->getClient();
            $replaces = $this->connection->fetchAssociative(
                "SELECT application_name, created_at FROM application_connections WHERE client_id = ? AND webhook_url = ? AND status = 'active'",
                [$client->getId()->toRfc4122(), $request->webhookUrl]);
            $out[] = ['client' => $client, 'obstacle' => $this->obstacle($membership, $request), 'replaces' => false === $replaces ? null : $replaces];
        }

        return $out;
    }

    /**
     * @return string the single-use code for the redirect back to the application (never stored or logged)
     *
     * @throws DomainRuleViolation when this person may not connect the application to that client now
     */
    public function approve(User $user, string $clientId, ConnectRequest $request): string
    {
        $membership = Uuid::isValid($clientId) ? $this->em->createQuery(
            'SELECT m, c FROM App\Entity\ClientMembership m JOIN m.client c WHERE m.user = :u AND c.id = :c')
            ->setParameter('u', $user->getId(), 'uuid')->setParameter('c', Uuid::fromString($clientId), 'uuid')->getOneOrNullResult() : null;
        if (!$membership instanceof ClientMembership) {
            // A client the person does not belong to is answered like one that does not exist.
            throw new DomainRuleViolation('Choose one of your workspaces.');
        }
        $obstacle = $this->obstacle($membership, $request);
        if (null !== $obstacle) {
            throw new DomainRuleViolation($obstacle);
        }
        $client = $membership->getClient();
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = Clock::now()->modify('+'.self::CODE_TTL_SECONDS.' seconds');
        $connection = new ApplicationConnection($client, $request->applicationName, $request->redirectUri, $request->webhookUrl, $user,
            hash('sha256', $code), $request->codeChallenge, $expires);
        $this->em->wrapInTransaction(function () use ($connection, $client, $user, $request): void {
            // Approvals whose code was never exchanged leave nothing but their row: gone a day after the code expired.
            $this->connection->executeStatement("DELETE FROM application_connections WHERE status = 'pending' AND code_expires_at < now() - interval '1 day'");
            $this->em->persist($connection);
            $this->em->flush();
            $this->audit->record(AuditActor::user($user), 'application_connection.approved', 'application_connection', $connection->getId()->toRfc4122(), [
                'client_id' => $client->getId()->toRfc4122(), 'application_name' => $request->applicationName,
                'redirect_origin' => $request->redirectOrigin(), 'webhook_url' => $request->webhookUrl,
                'code_expires_at' => Clock::rfc3339($connection->getCodeExpiresAt())]);
        });

        return $code;
    }

    /**
     * @return array<string, mixed> OpenAPI ConnectCredentials: the only time the API key and the signing secret exist in the clear
     *
     * @throws ConnectFailure
     */
    public function exchange(string $code, string $codeVerifier, string $redirectUri): array
    {
        if (1 !== preg_match(self::CODE_PATTERN, $code) || 1 !== preg_match(self::VERIFIER_PATTERN, $codeVerifier)) {
            throw ConnectFailure::invalidCode();
        }
        $actor = AuditActor::system(self::ACTOR);
        try {
            return $this->connection->transactional(function () use ($code, $codeVerifier, $redirectUri, $actor): array {
                // The row lock makes the code single-use under concurrent exchanges.
                $row = $this->connection->fetchAssociative(
                    'SELECT id::text AS id, code_expires_at > now() AS live FROM application_connections WHERE code_hash = ? FOR UPDATE', [hash('sha256', $code)]);
                $connection = false === $row ? null : $this->em->find(ApplicationConnection::class, Uuid::fromString($row['id']));
                if (null === $connection) {
                    throw ConnectFailure::invalidCode();
                }
                $this->em->refresh($connection);
                $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
                if (!$connection->isPending() || true !== filter_var($row['live'], \FILTER_VALIDATE_BOOLEAN)
                    || !hash_equals($connection->getCodeChallenge(), $challenge) || !hash_equals($connection->getRedirectUri(), $redirectUri)) {
                    throw ConnectFailure::invalidCode();
                }
                $client = $connection->getClient();
                $this->em->refresh($client, LockMode::PESSIMISTIC_WRITE);   // one exchange, key or endpoint creation per client at a time
                if (ClientStatus::Active !== $client->getStatus()) {
                    throw ConnectFailure::refused(\sprintf('The client is %s: an application can be connected only to an active client.',
                        strtolower(Labels::label($client->getStatus()->value, 'client_status'))));
                }
                // The same application connecting again replaces its earlier connection, here, where the new credentials are handed over.
                foreach ($this->em->getRepository(ApplicationConnection::class)->findBy(
                    ['client' => $client, 'webhookUrl' => $connection->getWebhookUrl(), 'status' => ApplicationConnectionStatus::Active]) as $earlier) {
                    $this->end($earlier, $actor, 'replaced', ['replaced_by' => $connection->getId()->toRfc4122()]);
                }
                $obstacle = $this->limitObstacle($client, $connection->getWebhookUrl());
                if (null !== $obstacle) {
                    throw ConnectFailure::refused($obstacle);
                }
                [$key, $rawKey] = $this->keys->create($client, $connection->getApplicationName(), $actor);
                [$endpoint, $secret] = $this->endpoints->create($client, $connection->getWebhookUrl(), self::EVENTS, $actor);
                $connection->activate($key, $endpoint);
                $this->em->flush();
                $this->audit->record($actor, 'application_connection.connected', 'application_connection', $connection->getId()->toRfc4122(), [
                    'client_id' => $client->getId()->toRfc4122(), 'application_name' => $connection->getApplicationName(),
                    'approved_by_user_id' => $connection->getApprovedBy()->getId()->toRfc4122(), 'api_key_id' => $key->getId()->toRfc4122(),
                    'key_prefix' => $key->getKeyPrefix(), 'webhook_endpoint_id' => $endpoint->getId()->toRfc4122()]);

                return [
                    'connection_id' => $connection->getId()->toRfc4122(),
                    'application_name' => $connection->getApplicationName(),
                    'client_name' => $client->getCompanyName(),
                    'api_base_url' => rtrim($this->publicBaseUrl, '/').'/v1',
                    'api_key' => $rawKey,
                    'webhook' => ['endpoint_id' => $endpoint->getId()->toRfc4122(), 'url' => $endpoint->getUrl(), 'signing_secret' => $secret,
                        'events' => $endpoint->getSubscribedEventTypes()],
                    'sending_domains' => $this->sendingDomains($client),
                ];
            });
        } catch (DomainRuleViolation $e) {
            // A limit reached, or a rule of the key or endpoint, between the approval and now.
            throw ConnectFailure::refused($e->getMessage());
        }
    }

    /** Ends a connection: its API key is revoked and its webhook endpoint removed. */
    public function revoke(ApplicationConnection $connection, AuditActor $actor, string $reason): void
    {
        if ($connection->isRevoked()) {
            return;
        }
        $this->em->wrapInTransaction(fn () => $this->end($connection, $actor, $reason));
    }

    /** The active connection an API key was issued to, if any. */
    public function forApiKey(ApiKey $key): ?ApplicationConnection
    {
        return $this->em->getRepository(ApplicationConnection::class)->findOneBy(['apiKey' => $key, 'status' => ApplicationConnectionStatus::Active]);
    }

    public function find(Client $client, string $id): ?ApplicationConnection
    {
        return Uuid::isValid($id) ? $this->em->getRepository(ApplicationConnection::class)->findOneBy(['id' => Uuid::fromString($id), 'client' => $client]) : null;
    }

    /**
     * A client's connections for its dashboard: active ones, approvals still waiting for the
     * application, then ended ones. Never the code hash or the code challenge.
     *
     * @return list<array<string, mixed>>
     */
    public function connections(string $clientId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT a.id::text AS id, a.application_name, a.webhook_url, a.redirect_uri, a.status, a.created_at, a.exchanged_at, a.revoked_at,
                   a.code_expires_at, k.key_prefix, k.last_used_at, u.email AS approved_by
              FROM application_connections a
              JOIN users u ON u.id = a.approved_by_user_id
              LEFT JOIN api_keys k ON k.id = a.api_key_id
             WHERE a.client_id = ? AND (a.status <> 'pending' OR a.code_expires_at > now())
             ORDER BY CASE a.status WHEN 'active' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END, a.created_at DESC, a.id DESC LIMIT 200
            SQL, [$clientId]);
    }

    /**
     * The client's sending domains as the API presents them (OpenAPI SendingDomain), by name.
     *
     * @return list<array<string, mixed>>
     */
    public function sendingDomains(Client $client): array
    {
        $domains = $this->em->getRepository(SendingDomain::class)->findBy(['client' => $client], ['domain' => 'ASC']);

        return array_map(fn (SendingDomain $d): array => Presenter::sendingDomain($d, $this->domainRule->canSend($d)), $domains);
    }

    /** @param array<string, mixed> $detail */
    private function end(ApplicationConnection $connection, AuditActor $actor, string $reason, array $detail = []): void
    {
        $key = $connection->getApiKey();
        if (null !== $key) {
            $this->keys->revoke($key, $actor);
        }
        $endpoint = $connection->getWebhookEndpoint();
        if (null !== $endpoint) {
            $this->endpoints->remove($endpoint, $actor);
        }
        $connection->revoke();
        $this->em->flush();
        $this->audit->record($actor, 'application_connection.revoked', 'application_connection', $connection->getId()->toRfc4122(), [
            'client_id' => $connection->getClient()->getId()->toRfc4122(), 'application_name' => $connection->getApplicationName(), 'reason' => $reason] + $detail);
    }

    /** Why this member cannot connect the application to their client now, or null. */
    private function obstacle(ClientMembership $membership, ConnectRequest $request): ?string
    {
        $client = $membership->getClient();
        if (ClientMembershipRole::Admin !== $membership->getRole()) {
            return \sprintf('Only a client admin can connect an application; you are a %s of %s.', $membership->getRole()->value, $client->getCompanyName());
        }
        if (ClientStatus::Active !== $client->getStatus()) {
            return \sprintf('%s is %s: an application can be connected only to an active client.', $client->getCompanyName(),
                strtolower(Labels::label($client->getStatus()->value, 'client_status')));
        }

        return $this->limitObstacle($client, $request->webhookUrl);
    }

    /**
     * The client's limits on usable API keys and webhook endpoints, counting out the active
     * connection to the same webhook URL, which the new one replaces.
     */
    private function limitObstacle(Client $client, string $webhookUrl): ?string
    {
        $counts = $this->connection->fetchAssociative(<<<'SQL'
            SELECT (SELECT count(*) FROM api_keys k WHERE k.client_id = :c AND k.revoked_at IS NULL AND (k.expires_at IS NULL OR k.expires_at > now())
                       AND NOT EXISTS (SELECT 1 FROM application_connections a WHERE a.api_key_id = k.id AND a.status = 'active' AND a.webhook_url = :u)) AS keys,
                   (SELECT count(*) FROM webhook_endpoints e WHERE e.client_id = :c AND e.status <> 'removed'
                       AND NOT EXISTS (SELECT 1 FROM application_connections a WHERE a.webhook_endpoint_id = e.id AND a.status = 'active' AND a.webhook_url = :u)) AS endpoints
            SQL, ['c' => $client->getId()->toRfc4122(), 'u' => $webhookUrl]) ?: ['keys' => 0, 'endpoints' => 0];
        $maxKeys = $this->limits->maxApiKeys($client);
        if ((int) $counts['keys'] >= $maxKeys) {
            return \sprintf('%s already has %d usable API key%s, and its limit is %d: nothing was created. Revoke a key or a connected application first, or ask the operator of this installation to raise the limit.',
                $client->getCompanyName(), $counts['keys'], 1 === (int) $counts['keys'] ? '' : 's', $maxKeys);
        }
        $maxEndpoints = $this->limits->maxWebhookEndpoints($client);
        if ((int) $counts['endpoints'] >= $maxEndpoints) {
            return \sprintf('%s already has %d webhook endpoint%s, and its limit is %d: nothing was created. Revoke a connected application first, or ask the operator of this installation to raise the limit.',
                $client->getCompanyName(), $counts['endpoints'], 1 === (int) $counts['endpoints'] ? '' : 's', $maxEndpoints);
        }

        return null;
    }
}
