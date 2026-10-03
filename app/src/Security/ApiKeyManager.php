<?php

declare(strict_types=1);

namespace App\Security;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Entity\ApiKey;
use App\Entity\Client;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates and revokes API keys (conventions: API).
 *
 * Raw key: "shk_" + base64url(32 CSPRNG bytes) = 256 bits. Stored: sha256(raw key)
 * as lower-case hex (indexed lookup; a fast hash is sufficient for high-entropy
 * keys) and the first 12 characters as a non-secret display prefix. The raw key
 * is returned once by create() and is never stored or logged.
 */
final class ApiKeyManager
{
    public const RAW_KEY_PATTERN = '/^shk_[A-Za-z0-9_-]{43}$/';
    private const PREFIX_LENGTH = 12;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public static function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    /** @return array{0: ApiKey, 1: string} the key and its raw value (shown once) */
    public function create(Client $client, ?string $name, AuditActor $actor): array
    {
        $raw = 'shk_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $key = new ApiKey($client, self::hash($raw), substr($raw, 0, self::PREFIX_LENGTH), $name);
        $this->em->wrapInTransaction(function () use ($key, $client, $actor): void {
            $this->em->persist($key);
            $this->em->flush();
            $this->audit->record($actor, 'api_key.created', 'api_key', $key->getId()->toRfc4122(), [
                'client_id' => $client->getId()->toRfc4122(),
                'key_prefix' => $key->getKeyPrefix(),
                'name' => $key->getName(),
            ]);
        });

        return [$key, $raw];
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
            ]);
        });
    }
}
