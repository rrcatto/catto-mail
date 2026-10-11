<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ApplicationConnectionStatus;
use App\Tenant\TenantOwned;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An application connected from the connect page (specification 2.12), managed through
 * App\Connect\ConnectService. The approval of a client admin stores the SHA-256 of a
 * single-use code and the application's PKCE code challenge, nothing secret. The API key and
 * the webhook endpoint exist from the exchange on, and end with the connection.
 */
#[ORM\Entity]
#[ORM\Table(name: 'application_connections')]
class ApplicationConnection implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'text', enumType: ApplicationConnectionStatus::class)]
    private ApplicationConnectionStatus $status = ApplicationConnectionStatus::Pending;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $exchangedAt = null;

    #[ORM\ManyToOne(targetEntity: ApiKey::class)]
    #[ORM\JoinColumn(name: 'api_key_id', nullable: true)]
    private ?ApiKey $apiKey = null;

    #[ORM\ManyToOne(targetEntity: WebhookEndpoint::class)]
    #[ORM\JoinColumn(name: 'webhook_endpoint_id', nullable: true)]
    private ?WebhookEndpoint $webhookEndpoint = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(name: 'client_id', nullable: false)]
        private Client $client,
        #[ORM\Column(type: 'text')]
        private string $applicationName,
        #[ORM\Column(type: 'text')]
        private string $redirectUri,
        #[ORM\Column(type: 'text')]
        private string $webhookUrl,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'approved_by_user_id', nullable: false)]
        private User $approvedBy,
        #[ORM\Column(type: 'text')]
        private string $codeHash,
        #[ORM\Column(type: 'text')]
        private string $codeChallenge,
        #[ORM\Column(type: 'timestamptz')]
        private \DateTimeImmutable $codeExpiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getApplicationName(): string { return $this->applicationName; }
    public function getRedirectUri(): string { return $this->redirectUri; }
    public function getWebhookUrl(): string { return $this->webhookUrl; }
    public function getStatus(): ApplicationConnectionStatus { return $this->status; }
    public function getApprovedBy(): User { return $this->approvedBy; }
    public function getCodeHash(): string { return $this->codeHash; }
    public function getCodeChallenge(): string { return $this->codeChallenge; }
    public function getCodeExpiresAt(): \DateTimeImmutable { return $this->codeExpiresAt; }
    public function getExchangedAt(): ?\DateTimeImmutable { return $this->exchangedAt; }
    public function getApiKey(): ?ApiKey { return $this->apiKey; }
    public function getWebhookEndpoint(): ?WebhookEndpoint { return $this->webhookEndpoint; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isPending(): bool { return ApplicationConnectionStatus::Pending === $this->status; }
    public function isActive(): bool { return ApplicationConnectionStatus::Active === $this->status; }
    public function isRevoked(): bool { return ApplicationConnectionStatus::Revoked === $this->status; }

    /** The exchange: the key and the endpoint were created in this transaction. */
    public function activate(ApiKey $key, WebhookEndpoint $endpoint): void
    {
        if (!$this->isPending()) {
            throw new \LogicException('Only a pending connection can be activated.');
        }
        $this->status = ApplicationConnectionStatus::Active;
        $this->exchangedAt = Clock::now();
        $this->apiKey = $key;
        $this->webhookEndpoint = $endpoint;
    }

    public function revoke(): void
    {
        $this->status = ApplicationConnectionStatus::Revoked;
        $this->revokedAt ??= Clock::now();
    }
}
