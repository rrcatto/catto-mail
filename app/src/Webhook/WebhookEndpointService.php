<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Crypto\Keyring;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\WebhookEndpoint;
use App\Enum\WebhookEndpointStatus;
use App\Enum\WebhookEventType;
use App\Util\Clock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Webhook endpoint registration and the signing-secret model (D-10, spec api.webhooks).
 *
 * Secrets are "whsec_" + base64url(32 CSPRNG bytes), returned once on creation or
 * rotation, and stored only encrypted (App\Crypto\Keyring, purpose bound to the
 * endpoint id). Rotation keeps the previous secret valid for
 * APP_WEBHOOK_SECRET_OVERLAP_HOURS, during which the worker sends both
 * signatures. URLs must be https outside development/test.
 */
final class WebhookEndpointService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Keyring $keyring,
        private readonly AuditLogger $audit,
        private readonly string $smarthostEnv,
        private readonly int $secretOverlapHours,
    ) {
    }

    /**
     * @param list<string> $eventTypes
     *
     * @return array{0: WebhookEndpoint, 1: string} the endpoint and its raw signing secret (shown once)
     */
    public function create(Client $client, string $url, array $eventTypes, AuditActor $actor): array
    {
        $this->assertUrl($url);
        $types = $this->eventTypes($eventTypes);
        $id = Uuid::v7();
        $secret = self::newSecret();
        $sealed = $this->keyring->encrypt($secret, self::purpose($id));
        $endpoint = new WebhookEndpoint($client, $url, $types, $sealed['ciphertext'], $sealed['key_id'], $id);

        $this->em->wrapInTransaction(function () use ($endpoint, $actor): void {
            $this->em->persist($endpoint);
            $this->em->flush();
            $this->audit->record($actor, 'webhook_endpoint.created', 'webhook_endpoint', $endpoint->getId()->toRfc4122(), [
                'client_id' => $endpoint->getClient()->getId()->toRfc4122(), 'url' => $endpoint->getUrl(),
                'subscribed_event_types' => $endpoint->getSubscribedEventTypes()]);
        });

        return [$endpoint, $secret];
    }

    /** @param list<string> $eventTypes */
    public function update(WebhookEndpoint $endpoint, string $url, array $eventTypes, AuditActor $actor): void
    {
        $this->assertUrl($url);
        $types = $this->eventTypes($eventTypes);
        $this->change($endpoint, $actor, 'webhook_endpoint.updated', static fn () => $endpoint->update($url, $types),
            ['url' => $url, 'subscribed_event_types' => $types]);
    }

    /** @return string the new raw secret (shown once) */
    public function rotateSecret(WebhookEndpoint $endpoint, AuditActor $actor): string
    {
        $secret = self::newSecret();
        $sealed = $this->keyring->encrypt($secret, self::purpose($endpoint->getId()));
        $expires = Clock::now()->modify(\sprintf('+%d hours', max(0, $this->secretOverlapHours)));
        $this->change($endpoint, $actor, 'webhook_endpoint.secret_rotated',
            static fn () => $endpoint->rotateSecret($sealed['ciphertext'], $sealed['key_id'], $expires),
            ['previous_secret_expires_at' => $expires->format(\DATE_RFC3339_EXTENDED)]);

        return $secret;
    }

    public function setStatus(WebhookEndpoint $endpoint, WebhookEndpointStatus $status, AuditActor $actor): void
    {
        if ($endpoint->getStatus() === $status) {
            return;
        }
        $this->change($endpoint, $actor, 'webhook_endpoint.'.$status->value, static fn () => $endpoint->setStatus($status));
    }

    /** @return list<string> the secrets the worker signs with at $at (WebhookSecrets) */
    public function activeSecrets(WebhookEndpoint $endpoint, ?\DateTimeImmutable $at = null): array
    {
        return (new WebhookSecrets($this->keyring))->active($endpoint, $at);
    }

    private static function newSecret(): string
    {
        return 'whsec_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function purpose(Uuid $endpointId): string
    {
        return WebhookSecrets::purpose($endpointId);
    }

    private function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $httpAllowed = \in_array($this->smarthostEnv, ['development', 'test'], true);
        if (false === $parts || !isset($parts['host']) || 1 === preg_match('/[\x00-\x20\x7F]/', $url) || isset($parts['user'])
            || !('https' === $scheme || ('http' === $scheme && $httpAllowed))) {
            throw new DomainRuleViolation($httpAllowed ? 'The webhook URL must be an absolute http(s) URL without credentials.'
                : 'The webhook URL must be an absolute https URL without credentials.');
        }
    }

    /**
     * @param list<string> $eventTypes
     *
     * @return list<string>
     */
    private function eventTypes(array $eventTypes): array
    {
        $types = array_values(array_unique($eventTypes));
        foreach ($types as $type) {
            if (null === WebhookEventType::tryFrom($type) || WebhookEventType::WebhookTest->value === $type) {
                throw new DomainRuleViolation(\sprintf('"%s" is not a subscribable webhook event type.', $type));
            }
        }
        if ([] === $types) {
            throw new DomainRuleViolation('Subscribe to at least one event type.');
        }
        sort($types);

        return $types;
    }

    /** @param array<string, mixed> $detail */
    private function change(WebhookEndpoint $endpoint, AuditActor $actor, string $action, callable $mutation, array $detail = []): void
    {
        $this->em->wrapInTransaction(function () use ($endpoint, $actor, $action, $mutation, $detail): void {
            $mutation();
            $this->em->flush();
            $this->audit->record($actor, $action, 'webhook_endpoint', $endpoint->getId()->toRfc4122(),
                ['client_id' => $endpoint->getClient()->getId()->toRfc4122()] + $detail);
        });
    }
}
