<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Staged recipient input (D-01). Inserted in bulk by App\Sending\SendJobService
 * while the job is collecting; immutable once the job is sealed, except that Go
 * purges the rendered content after Postfix acceptance (D-14). Holds no SMTP state.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'send_job_recipients')]
class SendJobRecipient
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: SendJob::class)]
    #[ORM\JoinColumn(name: 'send_job_id', nullable: false)]
    private SendJob $sendJob;

    #[ORM\ManyToOne(targetEntity: SendJobRecipientBatch::class)]
    #[ORM\JoinColumn(name: 'batch_id', nullable: false)]
    private SendJobRecipientBatch $batch;

    #[ORM\Column(type: 'text')]
    private string $externalRecipientReference;

    #[ORM\Column(type: 'text')]
    private string $emailAddress;

    #[ORM\Column(type: 'text')]
    private string $normalizedAddress;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $htmlBody = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $textBody = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $unsubscribeUrl = null;

    #[ORM\Column(type: 'integer')]
    private int $contentBytes;

    #[ORM\Column(type: 'text')]
    private string $contentSha256;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $contentPurgedAt = null;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getSendJob(): SendJob { return $this->sendJob; }
    public function getBatch(): SendJobRecipientBatch { return $this->batch; }
    public function getExternalRecipientReference(): string { return $this->externalRecipientReference; }
    public function getEmailAddress(): string { return $this->emailAddress; }
    public function getNormalizedAddress(): string { return $this->normalizedAddress; }
    public function getSubject(): ?string { return $this->subject; }
    public function getHtmlBody(): ?string { return $this->htmlBody; }
    public function getTextBody(): ?string { return $this->textBody; }
    public function getUnsubscribeUrl(): ?string { return $this->unsubscribeUrl; }
    public function getContentBytes(): int { return $this->contentBytes; }
    public function getContentSha256(): string { return $this->contentSha256; }
    public function getContentPurgedAt(): ?\DateTimeImmutable { return $this->contentPurgedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
