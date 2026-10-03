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

    /** Clients that may create new sending work (OpenAPI Forbidden: "suspended or pending approval"). */
    public function maySend(): bool
    {
        return \in_array($this->status, [ClientStatus::Active, ClientStatus::Throttled], true);
    }

    /** A closed client's API keys no longer authenticate. */
    public function isClosed(): bool
    {
        return ClientStatus::Closed === $this->status;
    }
}
