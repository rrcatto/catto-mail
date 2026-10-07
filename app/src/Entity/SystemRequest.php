<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Work the web application asks of the host agent (`system_requests`, specification 2.11). A read-only mapping: the
 * services write this table with DBAL (App\System, App\AddressBatch).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'system_requests')]
class SystemRequest
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'action', type: 'text')]
    private string $action;

    #[ORM\Column(name: 'params_json', type: 'jsonb_map')]
    private array $paramsJson = [];

    #[ORM\Column(name: 'status', type: 'text')]
    private string $status;

    #[ORM\Column(name: 'requested_by_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $requestedByUserId = null;

    #[ORM\Column(name: 'note', type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'requested_at', type: 'timestamptz')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'started_at', type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'result_summary', type: 'text', nullable: true)]
    private ?string $resultSummary = null;

    #[ORM\Column(name: 'result_json', type: 'jsonb_map')]
    private array $resultJson = [];

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getAction(): string { return $this->action; }
    public function getParamsJson(): array { return $this->paramsJson; }
    public function getStatus(): string { return $this->status; }
    public function getRequestedByUserId(): ?Uuid { return $this->requestedByUserId; }
    public function getNote(): ?string { return $this->note; }
    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function getResultSummary(): ?string { return $this->resultSummary; }
    public function getResultJson(): array { return $this->resultJson; }
}
