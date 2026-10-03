<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ConfidenceLevel;
use App\Enum\DomainStatus;
use App\Enum\OverallClassification;
use App\Enum\SmtpStatus;
use App\Enum\SyntaxStatus;
use App\Enum\TypoReasonCode;
use App\Enum\ValidationProcessingState;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One submitted address of a validation job. Symfony inserts it (bulk, see
 * App\Validation\ValidationJobService) with the verbatim `original_address` and
 * `processing_state = pending`; every result and lease field belongs to the
 * Python validator. Symfony never updates a row (grant: S I D).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'validation_addresses')]
class ValidationAddress
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ValidationJob::class)]
    #[ORM\JoinColumn(name: 'job_id', nullable: false)]
    private ValidationJob $job;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $externalAddressReference = null;

    #[ORM\Column(type: 'text')]
    private string $originalAddress;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $normalizedAddress = null;

    #[ORM\Column(type: 'text', nullable: true, enumType: SyntaxStatus::class)]
    private ?SyntaxStatus $syntaxStatus = null;

    #[ORM\Column(type: 'text', nullable: true, enumType: DomainStatus::class)]
    private ?DomainStatus $domainStatus = null;

    #[ORM\Column(type: 'text', nullable: true, enumType: SmtpStatus::class)]
    private ?SmtpStatus $smtpStatus = null;

    #[ORM\Column(name: 'is_role', type: 'boolean', nullable: true)]
    private ?bool $isRole = null;

    #[ORM\Column(name: 'is_disposable', type: 'boolean', nullable: true)]
    private ?bool $isDisposable = null;

    #[ORM\Column(name: 'is_catch_all_or_accept_all', type: 'boolean', nullable: true)]
    private ?bool $isCatchAllOrAcceptAll = null;

    #[ORM\Column(name: 'is_domain_typo_suspected', type: 'boolean', nullable: true)]
    private ?bool $isDomainTypoSuspected = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $suggestedAddress = null;

    #[ORM\Column(type: 'text', nullable: true, enumType: TypoReasonCode::class)]
    private ?TypoReasonCode $suggestionReasonCode = null;

    #[ORM\Column(type: 'text', nullable: true, enumType: ConfidenceLevel::class)]
    private ?ConfidenceLevel $suggestionConfidence = null;

    #[ORM\Column(type: 'text', nullable: true, enumType: OverallClassification::class)]
    private ?OverallClassification $overallClassification = null;

    #[ORM\Column(type: 'text', nullable: true, enumType: ConfidenceLevel::class)]
    private ?ConfidenceLevel $confidence = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $diagnosticCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $diagnosticText = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    #[ORM\Column(type: 'text', enumType: ValidationProcessingState::class)]
    private ValidationProcessingState $processingState = ValidationProcessingState::Pending;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $claimedBy = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $leaseExpiresAt = null;

    #[ORM\Column(type: 'integer')]
    private int $attemptCount = 0;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getJob(): ValidationJob { return $this->job; }
    public function getExternalAddressReference(): ?string { return $this->externalAddressReference; }
    public function getOriginalAddress(): string { return $this->originalAddress; }
    public function getNormalizedAddress(): ?string { return $this->normalizedAddress; }
    public function getSyntaxStatus(): ?SyntaxStatus { return $this->syntaxStatus; }
    public function getDomainStatus(): ?DomainStatus { return $this->domainStatus; }
    public function getSmtpStatus(): ?SmtpStatus { return $this->smtpStatus; }
    public function isRole(): ?bool { return $this->isRole; }
    public function isDisposable(): ?bool { return $this->isDisposable; }
    public function isCatchAllOrAcceptAll(): ?bool { return $this->isCatchAllOrAcceptAll; }
    public function isDomainTypoSuspected(): ?bool { return $this->isDomainTypoSuspected; }
    public function getSuggestedAddress(): ?string { return $this->suggestedAddress; }
    public function getSuggestionReasonCode(): ?TypoReasonCode { return $this->suggestionReasonCode; }
    public function getSuggestionConfidence(): ?ConfidenceLevel { return $this->suggestionConfidence; }
    public function getOverallClassification(): ?OverallClassification { return $this->overallClassification; }
    public function getConfidence(): ?ConfidenceLevel { return $this->confidence; }
    public function getDiagnosticCode(): ?string { return $this->diagnosticCode; }
    public function getDiagnosticText(): ?string { return $this->diagnosticText; }
    public function getCheckedAt(): ?\DateTimeImmutable { return $this->checkedAt; }
    public function getProcessingState(): ValidationProcessingState { return $this->processingState; }
    public function getClaimedBy(): ?string { return $this->claimedBy; }
    public function getLeaseExpiresAt(): ?\DateTimeImmutable { return $this->leaseExpiresAt; }
    public function getAttemptCount(): int { return $this->attemptCount; }
    public function getNextAttemptAt(): ?\DateTimeImmutable { return $this->nextAttemptAt; }
    public function getLastError(): ?string { return $this->lastError; }
}
