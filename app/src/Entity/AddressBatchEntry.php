<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One input row of an address batch, or an accepted typo correction (`address_batch_entries`, specification 2.11). A read-only mapping: the
 * services write this table with DBAL (App\System, App\AddressBatch).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'address_batch_entries')]
class AddressBatchEntry
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'batch_id', type: 'uuid')]
    private Uuid $batchId;

    #[ORM\Column(name: 'row_number', type: 'integer')]
    private int $rowNumber;

    #[ORM\Column(name: 'original_value', type: 'text')]
    private string $originalValue;

    #[ORM\Column(name: 'normalized_address', type: 'text', nullable: true)]
    private ?string $normalizedAddress = null;

    #[ORM\Column(name: 'outcome', type: 'text')]
    private string $outcome;

    #[ORM\Column(name: 'outcome_detail', type: 'text', nullable: true)]
    private ?string $outcomeDetail = null;

    #[ORM\Column(name: 'duplicate_of_entry_id', type: 'uuid', nullable: true)]
    private ?Uuid $duplicateOfEntryId = null;

    #[ORM\Column(name: 'corrects_entry_id', type: 'uuid', nullable: true)]
    private ?Uuid $correctsEntryId = null;

    #[ORM\Column(name: 'validation_address_id', type: 'uuid', nullable: true)]
    private ?Uuid $validationAddressId = null;

    #[ORM\Column(name: 'review_decision', type: 'text', nullable: true)]
    private ?string $reviewDecision = null;

    #[ORM\Column(name: 'typo_decision', type: 'text', nullable: true)]
    private ?string $typoDecision = null;

    #[ORM\Column(name: 'consent_state', type: 'text')]
    private string $consentState;

    #[ORM\Column(name: 'consent_changed_at', type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $consentChangedAt = null;

    #[ORM\Column(name: 'response_token_hash', type: 'text', nullable: true)]
    private ?string $responseTokenHash = null;

    #[ORM\Column(name: 'response_token_expires_at', type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $responseTokenExpiresAt = null;

    #[ORM\Column(name: 'created_at', type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getBatchId(): Uuid { return $this->batchId; }
    public function getRowNumber(): int { return $this->rowNumber; }
    public function getOriginalValue(): string { return $this->originalValue; }
    public function getNormalizedAddress(): ?string { return $this->normalizedAddress; }
    public function getOutcome(): string { return $this->outcome; }
    public function getOutcomeDetail(): ?string { return $this->outcomeDetail; }
    public function getDuplicateOfEntryId(): ?Uuid { return $this->duplicateOfEntryId; }
    public function getCorrectsEntryId(): ?Uuid { return $this->correctsEntryId; }
    public function getValidationAddressId(): ?Uuid { return $this->validationAddressId; }
    public function getReviewDecision(): ?string { return $this->reviewDecision; }
    public function getTypoDecision(): ?string { return $this->typoDecision; }
    public function getConsentState(): string { return $this->consentState; }
    public function getConsentChangedAt(): ?\DateTimeImmutable { return $this->consentChangedAt; }
    public function getResponseTokenHash(): ?string { return $this->responseTokenHash; }
    public function getResponseTokenExpiresAt(): ?\DateTimeImmutable { return $this->responseTokenExpiresAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
