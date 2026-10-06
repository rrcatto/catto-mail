<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WebhookDeliveryStatus;
use App\Enum\WebhookEventType;
use App\Tenant\TenantOwned;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** One event x endpoint delivery with its exact payload (webhook worker, Phase 7). */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'webhook_deliveries')]
class WebhookDelivery implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: false)]
    private Client $client;

    #[ORM\ManyToOne(targetEntity: WebhookEvent::class)]
    #[ORM\JoinColumn(name: 'webhook_event_id', nullable: false)]
    private WebhookEvent $webhookEvent;

    #[ORM\ManyToOne(targetEntity: WebhookEndpoint::class)]
    #[ORM\JoinColumn(name: 'webhook_endpoint_id', nullable: false)]
    private WebhookEndpoint $webhookEndpoint;

    #[ORM\Column(type: 'text', enumType: WebhookEventType::class)]
    private WebhookEventType $eventType;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'payload_json', type: 'jsonb_map')]
    private array $payload;

    #[ORM\Column(type: 'text')]
    private string $payloadHash;

    #[ORM\Column(type: 'text', enumType: WebhookDeliveryStatus::class)]
    private WebhookDeliveryStatus $status = WebhookDeliveryStatus::Pending;

    #[ORM\Column(type: 'integer')]
    private int $attemptCount = 0;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $claimedBy = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $leaseExpiresAt = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $lastResponseStatus = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    /** Bounded (≤ 1024 characters) start of the last response body, for diagnostics. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastResponseExcerpt = null;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getWebhookEvent(): WebhookEvent { return $this->webhookEvent; }
    public function getWebhookEndpoint(): WebhookEndpoint { return $this->webhookEndpoint; }
    public function getEventType(): WebhookEventType { return $this->eventType; }
    public function getPayload(): array { return $this->payload; }
    public function getPayloadHash(): string { return $this->payloadHash; }
    public function getStatus(): WebhookDeliveryStatus { return $this->status; }
    public function getAttemptCount(): int { return $this->attemptCount; }
    public function getNextAttemptAt(): ?\DateTimeImmutable { return $this->nextAttemptAt; }
    public function getClaimedBy(): ?string { return $this->claimedBy; }
    public function getLeaseExpiresAt(): ?\DateTimeImmutable { return $this->leaseExpiresAt; }
    public function getLastResponseStatus(): ?int { return $this->lastResponseStatus; }
    public function getLastError(): ?string { return $this->lastError; }
    public function getDeliveredAt(): ?\DateTimeImmutable { return $this->deliveredAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getLastAttemptAt(): ?\DateTimeImmutable { return $this->lastAttemptAt; }
    public function getLastResponseExcerpt(): ?string { return $this->lastResponseExcerpt; }
}
