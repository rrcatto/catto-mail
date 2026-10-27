<?php

declare(strict_types=1);

namespace App\Entity;

use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A single-use emailed sign-in link, or the verification link of a public sign-up or an
 * invitation (purpose sign_up, with the organisation's name). Only the SHA-256 of the token is stored.
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

    /** sign_in, or sign_up: the verification link of a public sign-up (no user yet). */
    #[ORM\Column(type: 'text')]
    private string $purpose = 'sign_in';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $organisationName = null;

    /** A sign-up link that came from an operator's invitation (2026-10-27). */
    #[ORM\ManyToOne(targetEntity: ClientInvitation::class)]
    #[ORM\JoinColumn(name: 'invitation_id', nullable: true)]
    private ?ClientInvitation $invitation = null;

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
    public function getPurpose(): string { return $this->purpose; }
    public function getOrganisationName(): ?string { return $this->organisationName; }
}
