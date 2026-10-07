<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The latest result of one system check (`system_checks`, specification 2.11). A read-only mapping: the
 * services write this table with DBAL (App\System, App\AddressBatch).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'system_checks')]
class SystemCheck
{
    #[ORM\Id]
    #[ORM\Column(name: 'check_key', type: 'text')]
    private string $checkKey;

    #[ORM\Column(name: 'component', type: 'text')]
    private string $component;

    #[ORM\Column(name: 'title', type: 'text')]
    private string $title;

    #[ORM\Column(name: 'result', type: 'text')]
    private string $result;

    #[ORM\Column(name: 'summary', type: 'text')]
    private string $summary;

    #[ORM\Column(name: 'detail_json', type: 'jsonb_map')]
    private array $detailJson = [];

    #[ORM\Column(name: 'duration_ms', type: 'integer', nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(name: 'source', type: 'text')]
    private string $source;

    #[ORM\Column(name: 'ran_at', type: 'timestamptz')]
    private \DateTimeImmutable $ranAt;

    #[ORM\Column(name: 'changed_at', type: 'timestamptz')]
    private \DateTimeImmutable $changedAt;

    private function __construct()
    {
    }

    public function getCheckKey(): string { return $this->checkKey; }
    public function getComponent(): string { return $this->component; }
    public function getTitle(): string { return $this->title; }
    public function getResult(): string { return $this->result; }
    public function getSummary(): string { return $this->summary; }
    public function getDetailJson(): array { return $this->detailJson; }
    public function getDurationMs(): ?int { return $this->durationMs; }
    public function getSource(): string { return $this->source; }
    public function getRanAt(): \DateTimeImmutable { return $this->ranAt; }
    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }
}
