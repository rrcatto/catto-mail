<?php

declare(strict_types=1);

namespace App\Security;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Client\ClientLimitPolicy;
use App\Domain\DomainRuleViolation;
use App\Entity\ApiKey;
use App\Entity\Client;
use App\Util\Clock;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates and revokes API keys (conventions: API).
 *
 * Raw key: "shk_" + base64url(32 CSPRNG bytes) = 256 bits. Stored: sha256(raw key)
 * as lower-case hex (indexed lookup; a fast hash is sufficient for high-entropy
 * keys) and the first 12 characters as a non-secret display prefix. The raw key
 * is returned once by create() and is never stored or logged.
 *
 * Phase 9 lifecycle: a descriptive name, an optional expiry (an expired key fails
 * authentication exactly like a revoked one), last-used time (ApiKeyAuthenticator) and
 * revocation, all audited. A client holds at most its effective number of usable keys
 * (App\Client\ClientLimitPolicy::maxApiKeys); creations of one client serialise on the
 * client row, so concurrent creations cannot exceed it. Rotation without downtime:
 * create the replacement, deploy it, verify it is used (last_used_at), revoke the old key.
 */
final class ApiKeyManager
{
    public const RAW_KEY_PATTERN = '/^shk_[A-Za-z0-9_-]{43}$/';
    private const PREFIX_LENGTH = 12;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly ClientLimitPolicy $limits,
    ) {
    }

    public static function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    /** @return array{0: ApiKey, 1: string} the key and its raw value (shown once) */
    public function create(Client $client, ?string $name, AuditActor $actor, ?\DateTimeImmutable $expiresAt = null): array
    {
        $name = null === $name ? null : trim($name);
        if (null !== $name && (mb_strlen($name) > 100 || 1 === preg_match('/[\x00-\x1F\x7F]/', $name))) {
            throw new DomainRuleViolation('An API key name has at most 100 characters and no control characters.');
        }
        if (null !== $expiresAt && $expiresAt <= Clock::now()->modify('+1 minute')) {
            throw new DomainRuleViolation('An API key expiry must lie in the future.');
        }
        if ($client->isClosed()) {
            throw new DomainRuleViolation('A closed client gets no API keys.');
        }
        $raw = 'shk_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $key = new ApiKey($client, self::hash($raw), substr($raw, 0, self::PREFIX_LENGTH), '' === $name ? null : $name);
        $key->setExpiresAt($expiresAt);
        $this->assertBelowLimit($client);
        $this->em->wrapInTransaction(function () use ($key, $client, $actor): void {
            // Serialise key creation per client so concurrent requests cannot exceed the limit.
            $this->em->lock($client, LockMode::PESSIMISTIC_WRITE);
            $this->assertBelowLimit($client);
            $this->em->persist($key);
            $this->em->flush();
            $this->audit->record($actor, 'api_key.created', 'api_key', $key->getId()->toRfc4122(), [
                'client_id' => $client->getId()->toRfc4122(),
                'key_prefix' => $key->getKeyPrefix(),
                'name' => $key->getName(),
                'expires_at' => Clock::rfc3339($key->getExpiresAt()),
            ]);
        });

        return [$key, $raw];
    }

    private function assertBelowLimit(Client $client): void
    {
        $usable = (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM api_keys WHERE client_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now())',
            [$client->getId()->toRfc4122()]);
        if ($usable >= $this->limits->maxApiKeys($client)) {
            throw new DomainRuleViolation(\sprintf('The client already has %d usable API keys; its limit is %d. Revoke one before creating another.',
                $usable, $this->limits->maxApiKeys($client)));
        }
    }

    public function revoke(ApiKey $key, AuditActor $actor): void
    {
        if ($key->isRevoked()) {
            return;
        }
        $this->em->wrapInTransaction(function () use ($key, $actor): void {
            $key->revoke();
            $this->em->flush();
            $this->audit->record($actor, 'api_key.revoked', 'api_key', $key->getId()->toRfc4122(), [
                'client_id' => $key->getClient()->getId()->toRfc4122(),
                'key_prefix' => $key->getKeyPrefix(),
                'name' => $key->getName(),
            ]);
        });
    }
}
