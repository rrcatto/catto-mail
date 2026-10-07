<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PolicyAcceptanceSource;
use App\Tenant\TenantOwned;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A client's acceptance of a service-policy version (`client_policy_acceptances`,
 * Phase 9). Content-neutral: only the version identifier is recorded. Append-only.
 */
#[ORM\Entity]
#[ORM\Table(name: 'client_policy_acceptances')]
class ClientPolicyAcceptance implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $acceptedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(name: 'client_id', nullable: false)]
        private Client $client,
        #[ORM\Column(type: 'text')]
        private string $policyVersion,
        #[ORM\Column(type: 'text', enumType: PolicyAcceptanceSource::class)]
        private PolicyAcceptanceSource $source,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'accepted_by_user_id', nullable: true)]
        private ?User $acceptedBy = null,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $reference = null,
    ) {
        $this->id = Uuid::v7();
        $this->acceptedAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getPolicyVersion(): string { return $this->policyVersion; }
    public function getSource(): PolicyAcceptanceSource { return $this->source; }
    public function getAcceptedBy(): ?User { return $this->acceptedBy; }
    public function getReference(): ?string { return $this->reference; }
    public function getAcceptedAt(): \DateTimeImmutable { return $this->acceptedAt; }
}
