<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EventSource;
use App\Enum\FailureScope;
use App\Enum\MessageEventType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only message event (D-06). Transport events are written by Go with a
 * stable (event_source, source_event_key); Symfony writes only open/click events
 * from the tracking endpoints (Phase 6). Never updated.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'message_events')]
class MessageEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(name: 'message_id', nullable: false)]
    private Message $message;

    #[ORM\Column(type: 'text', enumType: MessageEventType::class)]
    private MessageEventType $eventType;

    #[ORM\Column(type: 'text', enumType: EventSource::class)]
    private EventSource $eventSource;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sourceEventKey = null;

    #[ORM\Column(type: 'text', nullable: true, enumType: FailureScope::class)]
    private ?FailureScope $failureScope = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $smtpCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $enhancedStatusCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $remoteHost = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $diagnostic = null;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'metadata_json', type: 'jsonb_map')]
    private array $metadata = [];

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $recordedAt;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getMessage(): Message { return $this->message; }
    public function getEventType(): MessageEventType { return $this->eventType; }
    public function getEventSource(): EventSource { return $this->eventSource; }
    public function getSourceEventKey(): ?string { return $this->sourceEventKey; }
    public function getFailureScope(): ?FailureScope { return $this->failureScope; }
    public function getSmtpCode(): ?int { return $this->smtpCode; }
    public function getEnhancedStatusCode(): ?string { return $this->enhancedStatusCode; }
    public function getRemoteHost(): ?string { return $this->remoteHost; }
    public function getDiagnostic(): ?string { return $this->diagnostic; }
    public function getMetadata(): array { return $this->metadata; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }
}
