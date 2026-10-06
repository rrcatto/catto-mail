<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Crypto\Keyring;
use App\Entity\WebhookEndpoint;
use App\Util\Clock;
use Symfony\Component\Uid\Uuid;

/**
 * Decrypts an endpoint's signing secrets only when a request is signed. Secrets
 * are never logged or stored in plaintext.
 */
final class WebhookSecrets
{
    public function __construct(private readonly Keyring $keyring)
    {
    }

    /**
     * The secrets to sign with at $at: the current one, plus the previous one while
     * its rotation overlap is open (a second `v1=` value).
     *
     * @return list<string>
     */
    public function active(WebhookEndpoint $endpoint, ?\DateTimeImmutable $at = null): array
    {
        $at ??= Clock::now();
        $purpose = self::purpose($endpoint->getId());
        $secrets = [$this->keyring->decrypt($endpoint->getSigningSecretCiphertext(), $endpoint->getSigningSecretKeyId(), $purpose)];
        if (null !== $endpoint->getPreviousSigningSecretCiphertext() && $endpoint->getPreviousSigningSecretExpiresAt() > $at) {
            $secrets[] = $this->keyring->decrypt($endpoint->getPreviousSigningSecretCiphertext(),
                (string) $endpoint->getPreviousSigningSecretKeyId(), $purpose);
        }

        return $secrets;
    }

    public static function purpose(Uuid $endpointId): string
    {
        return 'webhook_signing_secret:'.$endpointId->toRfc4122();
    }
}
