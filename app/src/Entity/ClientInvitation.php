<?php

declare(strict_types=1);

namespace App\Entity;

use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An operator's invitation to open an account (`client_invitations`, owner decision 2026-10-27).
 * Only the SHA-256 of the link's token is stored. Written and read with DBAL by
 * App\Client\Invitations; mapped for the schema model. Operator-only.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'client_invitations')]
class ClientInvitation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'text')]
    private string $tokenHash;

    #[ORM\Column(type: 'text')]
    private string $plan;

    #[ORM\Column(type: 'boolean')]
    private bool $policyAcceptanceRequired;

    /** Who the operator made it for (a private label). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false)]
    private User $createdBy;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    /** The account the invitation opened. */
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: true)]
    private ?Client $client = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    private function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getPlan(): string { return $this->plan; }
    public function getClient(): ?Client { return $this->client; }
}
