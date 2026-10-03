<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SuppressionReason;
use App\Enum\SuppressionScopeType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Transport suppression (client-scoped, or global when client is NULL).
 * `address_or_domain` is D-18 normalised. Behaviour is Phase 5.
 */
#[ORM\Entity]
#[ORM\Table(name: 'suppressions')]
class Suppression
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: true)]
    private ?Client $client = null;

    #[ORM\Column(type: 'text')]
    private string $addressOrDomain;

    #[ORM\Column(type: 'text', enumType: SuppressionScopeType::class)]
    private SuppressionScopeType $scopeType;

    #[ORM\Column(type: 'text', enumType: SuppressionReason::class)]
    private SuppressionReason $reason;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(name: 'source_message_id', nullable: true)]
    private ?Message $sourceMessage = null;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $liftedAt = null;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): ?Client { return $this->client; }
    public function getAddressOrDomain(): string { return $this->addressOrDomain; }
    public function getScopeType(): SuppressionScopeType { return $this->scopeType; }
    public function getReason(): SuppressionReason { return $this->reason; }
    public function getSourceMessage(): ?Message { return $this->sourceMessage; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function getLiftedAt(): ?\DateTimeImmutable { return $this->liftedAt; }
}
