<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WebhookEndpointStatus;
use App\Tenant\TenantOwned;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Client webhook registration (D-10). The signing secret is stored encrypted
 * with the APP_ENCRYPTION_KEYS keyring (*_key_id names the key). Rotation keeps
 * the previous secret valid until previous_signing_secret_expires_at.
 * Managed through App\Webhook\WebhookEndpointService.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_endpoints')]
class WebhookEndpoint implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'text', enumType: WebhookEndpointStatus::class)]
    private WebhookEndpointStatus $status = WebhookEndpointStatus::Enabled;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $signingSecretCreatedAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $previousSigningSecretCiphertext = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $previousSigningSecretKeyId = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $previousSigningSecretExpiresAt = null;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $subscribedEventTypes
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(name: 'client_id', nullable: false)]
        private Client $client,
        #[ORM\Column(type: 'text')]
        private string $url,
        #[ORM\Column(type: 'jsonb')]
        private array $subscribedEventTypes,
        #[ORM\Column(type: 'text')]
        private string $signingSecretCiphertext,
        #[ORM\Column(type: 'text')]
        private string $signingSecretKeyId,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->createdAt = $this->updatedAt = $this->signingSecretCreatedAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getUrl(): string { return $this->url; }
    public function getStatus(): WebhookEndpointStatus { return $this->status; }
    public function isEnabled(): bool { return WebhookEndpointStatus::Enabled === $this->status; }
    /** @return list<string> */
    public function getSubscribedEventTypes(): array { return $this->subscribedEventTypes; }
    public function getSigningSecretCiphertext(): string { return $this->signingSecretCiphertext; }
    public function getSigningSecretKeyId(): string { return $this->signingSecretKeyId; }
    public function getSigningSecretCreatedAt(): \DateTimeImmutable { return $this->signingSecretCreatedAt; }
    public function getPreviousSigningSecretCiphertext(): ?string { return $this->previousSigningSecretCiphertext; }
    public function getPreviousSigningSecretKeyId(): ?string { return $this->previousSigningSecretKeyId; }
    public function getPreviousSigningSecretExpiresAt(): ?\DateTimeImmutable { return $this->previousSigningSecretExpiresAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setStatus(WebhookEndpointStatus $status): void
    {
        $this->status = $status;
        $this->updatedAt = Clock::now();
    }

    /** @param list<string> $types */
    public function update(string $url, array $types): void
    {
        $this->url = $url;
        $this->subscribedEventTypes = $types;
        $this->updatedAt = Clock::now();
    }

    /** The current secret becomes the previous one until $previousExpiresAt. */
    public function rotateSecret(string $ciphertext, string $keyId, \DateTimeImmutable $previousExpiresAt): void
    {
        $now = Clock::now();
        $this->previousSigningSecretCiphertext = $this->signingSecretCiphertext;
        $this->previousSigningSecretKeyId = $this->signingSecretKeyId;
        $this->previousSigningSecretExpiresAt = $previousExpiresAt;
        $this->signingSecretCiphertext = $ciphertext;
        $this->signingSecretKeyId = $keyId;
        $this->signingSecretCreatedAt = $now;
        $this->updatedAt = $now;
    }

    /** Re-encrypt the stored secrets under another key (keyring rotation). */
    public function replaceCiphertexts(string $ciphertext, string $keyId, ?string $previousCiphertext, ?string $previousKeyId): void
    {
        $this->signingSecretCiphertext = $ciphertext;
        $this->signingSecretKeyId = $keyId;
        if (null !== $this->previousSigningSecretCiphertext) {
            $this->previousSigningSecretCiphertext = $previousCiphertext;
            $this->previousSigningSecretKeyId = $previousKeyId;
        }
        $this->updatedAt = Clock::now();
    }
}
