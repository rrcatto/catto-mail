<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OverallClassification;
use App\Enum\ValidationJobStatus;
use App\Tenant\TenantOwned;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Asynchronous validation job. Symfony creates it (`queued`) with its address
 * rows; the Python validator (Phase 3) processes it. Carries its own
 * Idempotency-Key and the canonical request hash (D-19, api.idempotency).
 */
#[ORM\Entity]
#[ORM\Table(name: 'validation_jobs')]
class ValidationJob implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'text', enumType: ValidationJobStatus::class)]
    private ValidationJobStatus $status = ValidationJobStatus::Queued;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $submittedAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $processedCount = 0;

    /** @var array<string, int> */
    #[ORM\Column(name: 'classification_counts_json', type: 'jsonb_map')]
    private array $classificationCounts = [];

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(name: 'client_id', nullable: false)]
        private Client $client,
        #[ORM\Column(type: 'text')]
        private string $idempotencyKey,
        #[ORM\Column(type: 'text')]
        private string $requestHash,
        #[ORM\Column(type: 'integer')]
        private int $totalAddresses,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $externalReference = null,
    ) {
        $this->id = Uuid::v7();
        $this->submittedAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getExternalReference(): ?string { return $this->externalReference; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getRequestHash(): string { return $this->requestHash; }
    public function getStatus(): ValidationJobStatus { return $this->status; }
    public function getSubmittedAt(): \DateTimeImmutable { return $this->submittedAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function getTotalAddresses(): int { return $this->totalAddresses; }
    public function getProcessedCount(): int { return $this->processedCount; }

    /** All six classification keys, always present (OpenAPI ClassificationCounts). */
    public function getClassificationCounts(): array
    {
        $counts = [];
        foreach (OverallClassification::cases() as $case) {
            $counts[$case->value] = (int) ($this->classificationCounts[$case->value] ?? 0);
        }

        return $counts;
    }
}
