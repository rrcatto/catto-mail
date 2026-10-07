<?php

declare(strict_types=1);

namespace App\Entity;

use App\Client\ClientStatusTransitions;
use App\Domain\DomainRuleViolation;
use App\Enum\ClientOrigin;
use App\Enum\ClientStatus;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Tenant root (`clients`). */
#[ORM\Entity]
#[ORM\Table(name: 'clients')]
class Client
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'text', enumType: ClientStatus::class)]
    private ClientStatus $status = ClientStatus::PendingApproval;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    /** D-30: operator-granted right to report recipient global opt-outs (default false). */
    #[ORM\Column(type: 'boolean')]
    private bool $canSubmitGlobalSuppressions = false;

    #[ORM\Column(type: 'text', enumType: ClientOrigin::class)]
    private ClientOrigin $origin = ClientOrigin::Operator;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $billingContactEmail = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $abuseContactEmail = null;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $statusChangedAt;

    /** The first transition to active (approval). */
    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** Whether approval needs an acceptance of the policy version in force (Phase 9). */
    #[ORM\Column(type: 'boolean')]
    private bool $policyAcceptanceRequired = true;

    public function __construct(
        #[ORM\Column(type: 'text')]
        private string $companyName,
        #[ORM\Column(type: 'text')]
        private string $contactEmail,
        #[ORM\Column(type: 'text')]
        private string $plan,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = Clock::now();
        $this->statusChangedAt = $this->createdAt;
    }

    public function getId(): Uuid { return $this->id; }
    public function getCompanyName(): string { return $this->companyName; }
    public function getContactEmail(): string { return $this->contactEmail; }
    public function getPlan(): string { return $this->plan; }
    public function getStatus(): ClientStatus { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function canSubmitGlobalSuppressions(): bool { return $this->canSubmitGlobalSuppressions; }
    public function getOrigin(): ClientOrigin { return $this->origin; }
    public function getBillingContactEmail(): ?string { return $this->billingContactEmail; }
    public function getAbuseContactEmail(): ?string { return $this->abuseContactEmail; }
    public function getStatusChangedAt(): \DateTimeImmutable { return $this->statusChangedAt; }
    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function isPolicyAcceptanceRequired(): bool { return $this->policyAcceptanceRequired; }

    /** Only an operator changes this (smarthost:client:global-suppressions, audited). */
    public function setCanSubmitGlobalSuppressions(bool $allowed): void { $this->canSubmitGlobalSuppressions = $allowed; }

    public function setOrigin(ClientOrigin $origin): void { $this->origin = $origin; }
    public function setPolicyAcceptanceRequired(bool $required): void { $this->policyAcceptanceRequired = $required; }

    /** Account metadata (App\Client\ClientLifecycle::updateAccount, audited). */
    public function updateAccount(string $companyName, string $contactEmail, ?string $billingContactEmail, ?string $abuseContactEmail, string $plan): void
    {
        $this->companyName = $companyName;
        $this->contactEmail = $contactEmail;
        $this->billingContactEmail = $billingContactEmail;
        $this->abuseContactEmail = $abuseContactEmail;
        $this->plan = $plan;
    }

    /**
     * Applies a lifecycle transition (App\Client\ClientStatusTransitions): records when the
     * status changed, the first approval and the closure. Only App\Client\ClientLifecycle
     * calls this, inside its audited transaction.
     */
    public function transitionTo(ClientStatus $to, \DateTimeImmutable $at): void
    {
        if (!ClientStatusTransitions::allowed($this->status, $to)) {
            throw new DomainRuleViolation(\sprintf('A client cannot change from %s to %s.', $this->status->value, $to->value));
        }
        $this->status = $to;
        $this->statusChangedAt = $at;
        if (ClientStatus::Active === $to) {
            $this->approvedAt ??= $at;
        }
        if (ClientStatus::Closed === $to) {
            $this->closedAt = $at;
        }
    }

    /**
     * D-31: only active and throttled clients may create or add resource-consuming
     * work (validation jobs, send jobs, recipient batches, submit). Pending-approval
     * and suspended clients still authenticate and read; closed clients cannot
     * authenticate at all.
     */
    public function mayCreateWork(): bool
    {
        return \in_array($this->status, [ClientStatus::Active, ClientStatus::Throttled], true);
    }

    /** A closed client's API keys no longer authenticate. */
    public function isClosed(): bool
    {
        return ClientStatus::Closed === $this->status;
    }

    public function isThrottled(): bool
    {
        return ClientStatus::Throttled === $this->status;
    }
}
