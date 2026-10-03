<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MessageStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One recipient message, created and updated only by the Go delivery daemon
 * (D-01, Phase 4). Symfony reads it for the API. VERP/tracking tokens, return
 * path and queue id are internal and never exposed.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'messages')]
class Message
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: SendJob::class)]
    #[ORM\JoinColumn(name: 'send_job_id', nullable: false)]
    private SendJob $sendJob;

    #[ORM\ManyToOne(targetEntity: SendJobRecipient::class)]
    #[ORM\JoinColumn(name: 'send_job_recipient_id', nullable: false)]
    private SendJobRecipient $sendJobRecipient;

    #[ORM\Column(type: 'text')]
    private string $externalRecipientReference;

    #[ORM\Column(type: 'text')]
    private string $recipientAddress;

    #[ORM\Column(type: 'text')]
    private string $verpToken;

    #[ORM\Column(type: 'text')]
    private string $returnPath;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $trackingToken = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $postfixQueueId = null;

    #[ORM\Column(type: 'text', enumType: MessageStatus::class)]
    private MessageStatus $currentStatus = MessageStatus::Created;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getSendJob(): SendJob { return $this->sendJob; }
    public function getSendJobRecipient(): SendJobRecipient { return $this->sendJobRecipient; }
    public function getExternalRecipientReference(): string { return $this->externalRecipientReference; }
    public function getRecipientAddress(): string { return $this->recipientAddress; }
    public function getVerpToken(): string { return $this->verpToken; }
    public function getReturnPath(): string { return $this->returnPath; }
    public function getTrackingToken(): ?string { return $this->trackingToken; }
    public function getPostfixQueueId(): ?string { return $this->postfixQueueId; }
    public function getCurrentStatus(): MessageStatus { return $this->currentStatus; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
}
