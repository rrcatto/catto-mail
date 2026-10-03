<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MessageClass;
use App\Enum\MessageStatus;
use App\Enum\SendJobStatus;
use App\Tenant\TenantOwned;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Send job: job-level delivery parameters only; all content is per recipient
 * (D-08). Lifecycle (D-24): created `collecting` -> recipient batches -> submit
 * seals it (`queued`, queued_at). Later states, the lease fields and the summary
 * counts belong to the Go delivery daemon (Phase 4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'send_jobs')]
class SendJob implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $listId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $senderName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $replyToEmail = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $replyToName = null;

    #[ORM\Column(type: 'boolean')]
    private bool $trackOpens = false;

    #[ORM\Column(type: 'boolean')]
    private bool $trackClicks = false;

    #[ORM\Column(type: 'text', enumType: SendJobStatus::class)]
    private SendJobStatus $status = SendJobStatus::Collecting;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $queuedAt = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $dispatchCompletedAt = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $totalRecipients = 0;

    /** @var array<string, int> */
    #[ORM\Column(name: 'summary_counts_json', type: 'jsonb_map')]
    private array $summaryCounts = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $claimedBy = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $leaseExpiresAt = null;

    #[ORM\Column(type: 'integer')]
    private int $attemptCount = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(name: 'client_id', nullable: false)]
        private Client $client,
        #[ORM\Column(type: 'text')]
        private string $externalReference,
        #[ORM\Column(type: 'text')]
        private string $idempotencyKey,
        #[ORM\Column(type: 'text')]
        private string $requestHash,
        #[ORM\Column(type: 'text', enumType: MessageClass::class)]
        private MessageClass $messageClass,
        #[ORM\ManyToOne(targetEntity: SendingDomain::class)]
        #[ORM\JoinColumn(name: 'sending_domain_id', nullable: false)]
        private SendingDomain $sendingDomain,
        #[ORM\Column(type: 'text')]
        private string $senderEmail,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = Clock::now();
    }

    public function configure(?string $listId, ?string $senderName, ?string $replyToEmail, ?string $replyToName,
        bool $trackOpens, bool $trackClicks): void
    {
        $this->listId = $listId;
        $this->senderName = $senderName;
        $this->replyToEmail = $replyToEmail;
        $this->replyToName = $replyToName;
        $this->trackOpens = $trackOpens;
        $this->trackClicks = $trackClicks;
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getExternalReference(): string { return $this->externalReference; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getRequestHash(): string { return $this->requestHash; }
    public function getMessageClass(): MessageClass { return $this->messageClass; }
    public function getListId(): ?string { return $this->listId; }
    public function getSendingDomain(): SendingDomain { return $this->sendingDomain; }
    public function getSenderEmail(): string { return $this->senderEmail; }
    public function getSenderName(): ?string { return $this->senderName; }
    public function getReplyToEmail(): ?string { return $this->replyToEmail; }
    public function getReplyToName(): ?string { return $this->replyToName; }
    public function isTrackOpens(): bool { return $this->trackOpens; }
    public function isTrackClicks(): bool { return $this->trackClicks; }
    public function getStatus(): SendJobStatus { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getQueuedAt(): ?\DateTimeImmutable { return $this->queuedAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getDispatchCompletedAt(): ?\DateTimeImmutable { return $this->dispatchCompletedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function getTotalRecipients(): int { return $this->totalRecipients; }
    public function getClaimedBy(): ?string { return $this->claimedBy; }
    public function getLeaseExpiresAt(): ?\DateTimeImmutable { return $this->leaseExpiresAt; }
    public function getAttemptCount(): int { return $this->attemptCount; }
    public function getLastError(): ?string { return $this->lastError; }

    public function isCollecting(): bool { return SendJobStatus::Collecting === $this->status; }

    /** Sealed = submitted and beyond (the recipient set is immutable). */
    public function isSealed(): bool
    {
        return \in_array($this->status, [SendJobStatus::Queued, SendJobStatus::Processing,
            SendJobStatus::Dispatched, SendJobStatus::Completed], true);
    }

    /** All eleven message-status keys, always present (OpenAPI MessageStatusCounts). */
    public function getSummaryCounts(): array
    {
        $counts = [];
        foreach (MessageStatus::cases() as $case) {
            $counts[$case->value] = (int) ($this->summaryCounts[$case->value] ?? 0);
        }

        return $counts;
    }

    /** Running total; only App\Sending\SendJobService calls this, under the job row lock. */
    public function addRecipients(int $count): void
    {
        if (!$this->isCollecting()) {
            throw new \LogicException('Recipients can only be added while the job is collecting.');
        }
        $this->totalRecipients += $count;
    }

    /** Seal the recipient set (submit). Satisfies CHECK send_jobs_sealed. */
    public function seal(\DateTimeImmutable $at): void
    {
        if (!$this->isCollecting() || $this->totalRecipients < 1) {
            throw new \LogicException('Only a non-empty collecting job can be sealed.');
        }
        $this->status = SendJobStatus::Queued;
        $this->queuedAt = $at;
    }
}
