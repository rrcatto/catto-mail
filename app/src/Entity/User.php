<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GlobalRole;
use App\Enum\UserStatus;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Dashboard login account (D-12). The login email is unique case-insensitively
 * (`users_email_uq` on lower(email)); that rule is never used for mailbox matching.
 * API keys are never dashboard credentials.
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /** Symfony PasswordHasher output; NULL = invited, cannot log in yet. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(type: 'text', nullable: true, enumType: GlobalRole::class)]
    private ?GlobalRole $globalRole = null;

    #[ORM\Column(type: 'text', enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::Active;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    public function __construct(
        #[ORM\Column(type: 'text')]
        private string $email,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $displayName = null,
    ) {
        $this->id = Uuid::v7();
        $this->email = trim($email);
        $this->createdAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getDisplayName(): ?string { return $this->displayName; }
    public function getGlobalRole(): ?GlobalRole { return $this->globalRole; }
    public function setGlobalRole(?GlobalRole $role): void { $this->globalRole = $role; }
    public function isOperator(): bool { return GlobalRole::Operator === $this->globalRole; }
    public function getStatus(): UserStatus { return $this->status; }
    public function isDisabled(): bool { return UserStatus::Disabled === $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function getDisabledAt(): ?\DateTimeImmutable { return $this->disabledAt; }

    public function setPasswordHash(?string $hash): void { $this->passwordHash = $hash; }
    public function recordLogin(): void { $this->lastLoginAt = Clock::now(); }

    public function disable(): void
    {
        $this->status = UserStatus::Disabled;
        $this->disabledAt = Clock::now();
    }

    public function enable(): void
    {
        $this->status = UserStatus::Active;
        $this->disabledAt = null;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return $this->isOperator() ? ['ROLE_USER', 'ROLE_OPERATOR'] : ['ROLE_USER'];
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function eraseCredentials(): void
    {
    }
}
