<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WebhookEventType;
use App\Enum\WebhookSubjectType;
use App\Tenant\TenantOwned;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Transactional outbox row (D-22). Producers insert it in the same transaction
 * as the state change (App\Webhook\WebhookOutbox); only the Symfony webhook
 * worker (Phase 7) consumes it. `id` is the Smarthost-Event-Id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_events')]
class WebhookEvent implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: false)]
    private Client $client;

    #[ORM\Column(type: 'text', enumType: WebhookEventType::class)]
    private WebhookEventType $eventType;

    #[ORM\Column(type: 'text', enumType: WebhookSubjectType::class)]
    private WebhookSubjectType $subjectType;

    #[ORM\Column(type: 'uuid')]
    private Uuid $subjectId;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $fannedOutAt = null;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getEventType(): WebhookEventType { return $this->eventType; }
    public function getSubjectType(): WebhookSubjectType { return $this->subjectType; }
    public function getSubjectId(): Uuid { return $this->subjectId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getFannedOutAt(): ?\DateTimeImmutable { return $this->fannedOutAt; }
}
