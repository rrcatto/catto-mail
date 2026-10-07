<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host-agent report (host facts, backups, boot recovery) (`system_state`, specification 2.11). A read-only mapping: the
 * services write this table with DBAL (App\System, App\AddressBatch).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'system_state')]
class SystemStateEntry
{
    #[ORM\Id]
    #[ORM\Column(name: 'state_key', type: 'text')]
    private string $stateKey;

    #[ORM\Column(name: 'value_json', type: 'jsonb_map')]
    private array $valueJson = [];

    #[ORM\Column(name: 'updated_at', type: 'timestamptz')]
    private \DateTimeImmutable $updatedAt;

    private function __construct()
    {
    }

    public function getStateKey(): string { return $this->stateKey; }
    public function getValueJson(): array { return $this->valueJson; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
