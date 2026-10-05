<?php

declare(strict_types=1);

namespace App\Entity;

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
    }

    public function getId(): Uuid { return $this->id; }
    public function getCompanyName(): string { return $this->companyName; }
    public function getContactEmail(): string { return $this->contactEmail; }
    public function getPlan(): string { return $this->plan; }
    public function getStatus(): ClientStatus { return $this->status; }
    public function setStatus(ClientStatus $status): void { $this->status = $status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function canSubmitGlobalSuppressions(): bool { return $this->canSubmitGlobalSuppressions; }

    /** Only an operator changes this (smarthost:client:global-suppressions, audited). */
    public function setCanSubmitGlobalSuppressions(bool $allowed): void { $this->canSubmitGlobalSuppressions = $allowed; }

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
}
