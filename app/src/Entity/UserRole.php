<?php

declare(strict_types=1);

namespace App\Entity;

use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** A role held by a dashboard user, with who granted it. */
#[ORM\Entity]
#[ORM\Table(name: 'user_roles')]
class UserRole
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $assignedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false)]
        private User $user,
        #[ORM\ManyToOne(targetEntity: Role::class)]
        #[ORM\JoinColumn(name: 'role_id', nullable: false)]
        private Role $role,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'assigned_by', nullable: true)]
        private ?User $assignedBy = null,
    ) {
        $this->id = Uuid::v7();
        $this->assignedAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getRole(): Role { return $this->role; }
    public function getAssignedBy(): ?User { return $this->assignedBy; }
    public function getAssignedAt(): \DateTimeImmutable { return $this->assignedAt; }
}
