<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserStatus;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Dashboard account (D-12). Passwordless: a user signs in with a single-use link
 * emailed to this address (App\Security\LoginLinkService). The login email is
 * unique case-insensitively (`users_email_uq` on lower(email)); that rule is never
 * used for mailbox matching. API keys are never dashboard credentials.
 *
 * Installation-wide roles live in user_roles; their permission keys are resolved
 * per request by DashboardUserProvider and held here transiently (not mapped).
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'text', enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::Active;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    /** @var list<string> role keys, resolved per request (not mapped) */
    private array $roleKeys = [];

    /** @var list<string> permission keys, resolved per request (not mapped) */
    private array $permissions = [];

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
    public function getStatus(): UserStatus { return $this->status; }
    public function isDisabled(): bool { return UserStatus::Disabled === $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function getDisabledAt(): ?\DateTimeImmutable { return $this->disabledAt; }

    public function setDisplayName(?string $name): void { $this->displayName = null === $name || '' === trim($name) ? null : trim($name); }

    /**
     * @param list<string> $roleKeys
     * @param list<string> $permissions
     */
    public function setAccess(array $roleKeys, array $permissions): void
    {
        $this->roleKeys = $roleKeys;
        $this->permissions = $permissions;
    }

    /** @return list<string> */
    public function getRoleKeys(): array { return $this->roleKeys; }

    /** @return list<string> */
    public function getPermissions(): array { return $this->permissions; }

    public function hasPermission(string $key): bool { return \in_array($key, $this->permissions, true); }

    public function hasRole(string $key): bool { return \in_array($key, $this->roleKeys, true); }

    /** Holds any installation-wide permission (sees the operator area). */
    public function isPlatformUser(): bool { return [] !== $this->permissions; }
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

    /** Authorisation uses permission keys (App\Access\PermissionVoter), not Symfony roles. */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    /** The session stores only the identity; roles and permissions are re-resolved per request. */
    public function __serialize(): array
    {
        return ['id' => $this->id, 'email' => $this->email];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
    }
}
