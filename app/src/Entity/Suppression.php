<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SuppressionReason;
use App\Enum\SuppressionScopeType;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Transport suppression (client-scoped, or global when client is NULL), D-30.
 *
 * `address_or_domain` is D-18 normalised. `client` only scopes the row; the client
 * that reported a recipient global opt-out is `sourceClient` (client stays NULL).
 * System suppressions (hard_bounce, complaint, repeated_soft_bounce) are created by
 * the Go delivery daemon with `sourceMessage`/`sourceEvent`; Symfony creates only
 * client opt-outs and operator blocks. Rows are lifted, never deleted, except by
 * configured retention.
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

    #[ORM\ManyToOne(targetEntity: MessageEvent::class)]
    #[ORM\JoinColumn(name: 'source_event_id', nullable: true)]
    private ?MessageEvent $sourceEvent = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'source_client_id', nullable: true)]
    private ?Client $sourceClient = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $externalReference = null;


    private function __construct(string $addressOrDomain, SuppressionScopeType $scopeType, SuppressionReason $reason)
    {
        $this->id = Uuid::v7();
        $this->addressOrDomain = $addressOrDomain;
        $this->scopeType = $scopeType;
        $this->reason = $reason;
        $this->createdAt = Clock::now();
    }

    /**
     * A recipient global opt-out reported by a trusted client (D-30): global,
     * address-scoped, indefinite. The request that created it (and any later one
     * that found it active) is recorded in global_suppression_requests (D-38).
     */
    public static function recipientGlobalOptOut(Client $source, string $normalizedAddress, ?string $externalReference): self
    {
        $s = new self($normalizedAddress, SuppressionScopeType::Address, SuppressionReason::RecipientGlobalOptOut);
        $s->sourceClient = $source;
        $s->externalReference = $externalReference;

        return $s;
    }

    /** An operator block (operator_block or client_abuse_block), global when $scope is null. */
    public static function operatorBlock(?Client $scope, string $normalizedValue, SuppressionScopeType $scopeType, SuppressionReason $reason, ?\DateTimeImmutable $expiresAt): self
    {
        if (!$reason->isOperatorReason()) {
            throw new \LogicException("An operator cannot create a {$reason->value} suppression.");
        }
        $s = new self($normalizedValue, $scopeType, $reason);
        $s->client = $scope;
        $s->expiresAt = $expiresAt;

        return $s;
    }

    /** Sets lifted_at; false when the row was already lifted (lifting is idempotent). */
    public function lift(): bool
    {
        if (null !== $this->liftedAt) {
            return false;
        }
        $this->liftedAt = Clock::now();

        return true;
    }

    public function isActive(?\DateTimeImmutable $at = null): bool
    {
        $at ??= Clock::now();

        return null === $this->liftedAt && (null === $this->expiresAt || $this->expiresAt > $at);
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): ?Client { return $this->client; }
    public function getAddressOrDomain(): string { return $this->addressOrDomain; }
    public function getScopeType(): SuppressionScopeType { return $this->scopeType; }
    public function getReason(): SuppressionReason { return $this->reason; }
    public function getSourceMessage(): ?Message { return $this->sourceMessage; }
    public function getSourceEvent(): ?MessageEvent { return $this->sourceEvent; }
    public function getSourceClient(): ?Client { return $this->sourceClient; }
    public function getExternalReference(): ?string { return $this->externalReference; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function getLiftedAt(): ?\DateTimeImmutable { return $this->liftedAt; }
}
