<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ValidationEvidenceType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Append-only validator evidence (written by Python only; Symfony reads). */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'validation_evidence')]
class ValidationEvidence
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ValidationAddress::class)]
    #[ORM\JoinColumn(name: 'validation_address_id', nullable: false)]
    private ValidationAddress $validationAddress;

    #[ORM\Column(type: 'text', enumType: ValidationEvidenceType::class)]
    private ValidationEvidenceType $evidenceType;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $providerHost = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $responseCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $enhancedStatusCode = null;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'detail_json', type: 'jsonb_map')]
    private array $detail = [];

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $occurredAt;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getValidationAddress(): ValidationAddress { return $this->validationAddress; }
    public function getEvidenceType(): ValidationEvidenceType { return $this->evidenceType; }
    public function getProviderHost(): ?string { return $this->providerHost; }
    public function getResponseCode(): ?int { return $this->responseCode; }
    public function getEnhancedStatusCode(): ?string { return $this->enhancedStatusCode; }
    public function getDetail(): array { return $this->detail; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
