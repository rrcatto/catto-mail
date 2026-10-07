<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The installation-wide web emergency stop (one row) (`delivery_controls`, specification 2.11). A read-only mapping: the
 * services write this table with DBAL (App\System, App\AddressBatch).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'delivery_controls')]
class DeliveryControl
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'smallint')]
    private int $id;

    #[ORM\Column(name: 'emergency_stop', type: 'boolean')]
    private bool $emergencyStop;

    #[ORM\Column(name: 'changed_at', type: 'timestamptz')]
    private \DateTimeImmutable $changedAt;

    #[ORM\Column(name: 'changed_by_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $changedByUserId = null;

    #[ORM\Column(name: 'note', type: 'text', nullable: true)]
    private ?string $note = null;

    private function __construct()
    {
    }

    public function getId(): int { return $this->id; }
    public function isEmergencyStop(): bool { return $this->emergencyStop; }
    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }
    public function getChangedByUserId(): ?Uuid { return $this->changedByUserId; }
    public function getNote(): ?string { return $this->note; }
}
