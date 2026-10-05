<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DsnClassification;
use App\Enum\UnmatchedDsnStatus;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * DSN that resolved to no message (D-05). Written by the Go delivery daemon.
 *
 * Operator workflow (Phase 5, console commands until the Phase 6 dashboard):
 * open -> match_requested (operator names the message; Go then interprets the DSN,
 * appends the transport event and sets matched, or returns the row to open with the
 * reason in detail_json) or open -> dismissed (with a written reason). Rows are
 * retained after resolution.
 */
#[ORM\Entity]
#[ORM\Table(name: 'unmatched_dsns')]
class UnmatchedDsn
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: 'text')]
    private string $spoolIngestKey;

    #[ORM\Column(type: 'text')]
    private string $contentSha256;

    #[ORM\Column(type: 'text', enumType: DsnClassification::class)]
    private DsnClassification $classification;

    #[ORM\Column(type: 'text', enumType: UnmatchedDsnStatus::class)]
    private UnmatchedDsnStatus $status = UnmatchedDsnStatus::Open;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $verpToken = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $originalRecipient = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $finalRecipient = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $postfixQueueId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reportingMta = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $enhancedStatusCode = null;

    #[ORM\Column(type: 'text')]
    private string $rawMessage;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'detail_json', type: 'jsonb_map')]
    private array $detail = [];

    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(name: 'matched_message_id', nullable: true)]
    private ?Message $matchedMessage = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'resolution_requested_by', nullable: true)]
    private ?User $resolutionRequestedBy = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $resolutionRequestedAt = null;

    #[ORM\ManyToOne(targetEntity: MessageEvent::class)]
    #[ORM\JoinColumn(name: 'resolution_event_id', nullable: true)]
    private ?MessageEvent $resolutionEvent = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $resolutionNote = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function getSpoolIngestKey(): string { return $this->spoolIngestKey; }
    public function getContentSha256(): string { return $this->contentSha256; }
    public function getClassification(): DsnClassification { return $this->classification; }
    public function getStatus(): UnmatchedDsnStatus { return $this->status; }
    public function getVerpToken(): ?string { return $this->verpToken; }
    public function getOriginalRecipient(): ?string { return $this->originalRecipient; }
    public function getFinalRecipient(): ?string { return $this->finalRecipient; }
    public function getPostfixQueueId(): ?string { return $this->postfixQueueId; }
    public function getReportingMta(): ?string { return $this->reportingMta; }
    public function getEnhancedStatusCode(): ?string { return $this->enhancedStatusCode; }
    public function getRawMessage(): string { return $this->rawMessage; }
    public function getDetail(): array { return $this->detail; }
    public function getMatchedMessage(): ?Message { return $this->matchedMessage; }
    public function getResolutionRequestedBy(): ?User { return $this->resolutionRequestedBy; }
    public function getResolutionRequestedAt(): ?\DateTimeImmutable { return $this->resolutionRequestedAt; }
    public function getResolutionEvent(): ?MessageEvent { return $this->resolutionEvent; }
    public function getResolutionNote(): ?string { return $this->resolutionNote; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }

    /** open -> match_requested. Go performs the resolution (it never happens in Symfony). */
    public function requestMatch(Message $message, User $operator, ?string $note): void
    {
        if (UnmatchedDsnStatus::Open !== $this->status) {
            throw new \DomainException("Only an open unmatched DSN can be matched (status {$this->status->value}).");
        }
        $this->status = UnmatchedDsnStatus::MatchRequested;
        $this->matchedMessage = $message;
        $this->resolutionRequestedBy = $operator;
        $this->resolutionRequestedAt = Clock::now();
        $this->resolutionNote = $note;
    }

    /** open -> dismissed, with the operator's written reason. */
    public function dismiss(string $reason): void
    {
        if (UnmatchedDsnStatus::Open !== $this->status) {
            throw new \DomainException("Only an open unmatched DSN can be dismissed (status {$this->status->value}).");
        }
        if ('' === trim($reason)) {
            throw new \DomainException('A dismissal needs a written reason.');
        }
        $this->status = UnmatchedDsnStatus::Dismissed;
        $this->resolutionNote = trim($reason);
        $this->resolvedAt = Clock::now();
    }
}
