<?php

declare(strict_types=1);

namespace App\Entity;

use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** An installation-wide role (App\Access\RoleCatalog for the built-in ones). */
#[ORM\Entity]
#[ORM\Table(name: 'roles')]
class Role
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'boolean')]
    private bool $isBuiltin = false;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(name: 'role_key', type: 'text')]
        private string $roleKey,
        #[ORM\Column(type: 'text')]
        private string $name,
        #[ORM\Column(type: 'text')]
        private string $description = '',
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = $this->updatedAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getRoleKey(): string { return $this->roleKey; }
    public function getName(): string { return $this->name; }
    public function getDescription(): string { return $this->description; }
    public function isBuiltin(): bool { return $this->isBuiltin; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function rename(string $name, string $description): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->updatedAt = Clock::now();
    }
}
