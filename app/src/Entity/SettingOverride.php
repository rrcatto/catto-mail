<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A setting changed in the dashboard (`setting_overrides`): it takes precedence over the same
 * infra/.env variable without changing the file. A read-only mapping: App\System\SettingOverrides
 * writes this table with DBAL.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'setting_overrides')]
class SettingOverride
{
    #[ORM\Id]
    #[ORM\Column(name: 'name', type: 'text')]
    private string $name;

    #[ORM\Column(name: 'value', type: 'text')]
    private string $value;

    #[ORM\Column(name: 'reason', type: 'text')]
    private string $reason;

    #[ORM\Column(name: 'updated_at', type: 'timestamptz')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'updated_by_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $updatedByUserId = null;

    private function __construct()
    {
    }

    public function getName(): string { return $this->name; }
    public function getValue(): string { return $this->value; }
    public function getReason(): string { return $this->reason; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getUpdatedByUserId(): ?Uuid { return $this->updatedByUserId; }
}
