<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One kept result of a system check (history) (`system_check_runs`, specification 2.11). A read-only mapping: the
 * services write this table with DBAL (App\System, App\AddressBatch).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'system_check_runs')]
class SystemCheckRun
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'uuid')]
    private Uuid $id;

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

    #[ORM\Column(name: 'duration_ms', type: 'integer', nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(name: 'source', type: 'text')]
    private string $source;

    #[ORM\Column(name: 'run_trigger', type: 'text')]
    private string $runTrigger;

    #[ORM\Column(name: 'ran_at', type: 'timestamptz')]
    private \DateTimeImmutable $ranAt;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getCheckKey(): string { return $this->checkKey; }
    public function getComponent(): string { return $this->component; }
    public function getTitle(): string { return $this->title; }
    public function getResult(): string { return $this->result; }
    public function getSummary(): string { return $this->summary; }
    public function getDurationMs(): ?int { return $this->durationMs; }
    public function getSource(): string { return $this->source; }
    public function getRunTrigger(): string { return $this->runTrigger; }
    public function getRanAt(): \DateTimeImmutable { return $this->ranAt; }
}
