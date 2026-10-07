<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Progress of one setup-wizard step (`setup_steps`, specification 2.11). A read-only mapping: the
 * services write this table with DBAL (App\System, App\AddressBatch).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'setup_steps')]
class SetupStep
{
    #[ORM\Id]
    #[ORM\Column(name: 'step_key', type: 'text')]
    private string $stepKey;

    #[ORM\Column(name: 'state', type: 'text')]
    private string $state;

    #[ORM\Column(name: 'note', type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'updated_at', type: 'timestamptz')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'updated_by_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $updatedByUserId = null;

    private function __construct()
    {
    }

    public function getStepKey(): string { return $this->stepKey; }
    public function getState(): string { return $this->state; }
    public function getNote(): ?string { return $this->note; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getUpdatedByUserId(): ?Uuid { return $this->updatedByUserId; }
}
