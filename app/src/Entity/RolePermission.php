<?php

declare(strict_types=1);

namespace App\Entity;

use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** One permission key (App\Access\PermissionCatalog) granted by a role. */
#[ORM\Entity]
#[ORM\Table(name: 'role_permissions')]
class RolePermission
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Role::class)]
        #[ORM\JoinColumn(name: 'role_id', nullable: false)]
        private Role $role,
        #[ORM\Column(type: 'text')]
        private string $permissionKey,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getRole(): Role { return $this->role; }
    public function getPermissionKey(): string { return $this->permissionKey; }
}
