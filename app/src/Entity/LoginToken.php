<?php

declare(strict_types=1);

namespace App\Entity;

use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A single-use emailed sign-in link. Only the SHA-256 of the token is stored.
 * Written and redeemed with DBAL by App\Security\LoginLinkService (the redeem is
 * one atomic UPDATE ... RETURNING); mapped for the schema model and audits.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'auth_login_tokens')]
class LoginToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'text')]
    private string $email;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true)]
    private ?User $user = null;

    #[ORM\Column(type: 'text')]
    private string $tokenHash;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $returnPath = null;

    #[ORM\Column(type: 'text')]
    private string $requestedIpHash;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    private function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getUser(): ?User { return $this->user; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getUsedAt(): ?\DateTimeImmutable { return $this->usedAt; }
}
