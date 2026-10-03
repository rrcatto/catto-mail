<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UsageReferenceType;
use App\Enum\UsageType;
use App\Tenant\TenantOwned;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Usage metering row (written by the validator and the delivery daemon). */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'usage_records')]
class UsageRecord implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: false)]
    private Client $client;

    #[ORM\Column(type: 'text', enumType: UsageType::class)]
    private UsageType $usageType;

    #[ORM\Column(type: 'bigint')]
    private int|string $quantity;

    #[ORM\Column(type: 'text', enumType: UsageReferenceType::class)]
    private UsageReferenceType $referenceType;

    #[ORM\Column(type: 'uuid')]
    private Uuid $referenceId;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $occurredAt;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getUsageType(): UsageType { return $this->usageType; }
    public function getQuantity(): int { return (int) $this->quantity; }
    public function getReferenceType(): UsageReferenceType { return $this->referenceType; }
    public function getReferenceId(): Uuid { return $this->referenceId; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
