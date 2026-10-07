<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BillingReconciliationStatus;
use App\Enum\BillingStatementStatus;
use App\Tenant\TenantOwned;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Provider-neutral billing record (`billing_statements`, Phase 9): metered usage totals
 * of one client and period with their reconciliation and export state. Quantities
 * only; prices and invoices belong to the external billing system.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_statements')]
class BillingStatement implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'text', enumType: BillingStatementStatus::class)]
    private BillingStatementStatus $status = BillingStatementStatus::Draft;

    #[ORM\Column(type: 'text', enumType: BillingReconciliationStatus::class)]
    private BillingReconciliationStatus $reconciliationStatus;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'reconciliation_json', type: 'jsonb_map')]
    private array $reconciliation = [];

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $reconciledAt;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $finalizedAt = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $exportedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $externalReference = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $voidedAt = null;

    /** @param array<string, mixed> $reconciliation */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(name: 'client_id', nullable: false)]
        private Client $client,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $periodStart,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $periodEnd,
        BillingReconciliationStatus $reconciliationStatus,
        array $reconciliation,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = Clock::now();
        $this->recordReconciliation($reconciliationStatus, $reconciliation);
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getPeriodStart(): \DateTimeImmutable { return $this->periodStart; }
    public function getPeriodEnd(): \DateTimeImmutable { return $this->periodEnd; }
    public function getStatus(): BillingStatementStatus { return $this->status; }
    public function getReconciliationStatus(): BillingReconciliationStatus { return $this->reconciliationStatus; }
    /** @return array<string, mixed> */
    public function getReconciliation(): array { return $this->reconciliation; }
    public function getReconciledAt(): \DateTimeImmutable { return $this->reconciledAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getFinalizedAt(): ?\DateTimeImmutable { return $this->finalizedAt; }
    public function getExportedAt(): ?\DateTimeImmutable { return $this->exportedAt; }
    public function getExternalReference(): ?string { return $this->externalReference; }
    public function getVoidedAt(): ?\DateTimeImmutable { return $this->voidedAt; }

    /** @param array<string, mixed> $reconciliation */
    public function recordReconciliation(BillingReconciliationStatus $status, array $reconciliation): void
    {
        $this->reconciliationStatus = $status;
        $this->reconciliation = $reconciliation;
        $this->reconciledAt = Clock::now();
    }

    public function finalize(): void
    {
        $this->status = BillingStatementStatus::Finalized;
        $this->finalizedAt = Clock::now();
    }

    public function markExported(string $externalReference): void
    {
        $this->status = BillingStatementStatus::Exported;
        $this->exportedAt = Clock::now();
        $this->externalReference = $externalReference;
    }

    public function void(): void
    {
        $this->status = BillingStatementStatus::Void;
        $this->voidedAt = Clock::now();
    }
}
