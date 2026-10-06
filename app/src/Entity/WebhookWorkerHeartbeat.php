<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Liveness and counters of one webhook-worker process (written with DBAL by the worker). */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'webhook_worker_heartbeats')]
class WebhookWorkerHeartbeat
{
    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private string $workerId;

    #[ORM\Column(type: 'text')]
    private string $version;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $stoppedAt = null;

    #[ORM\Column(type: 'bigint')]
    private string $attempts = '0';

    #[ORM\Column(type: 'bigint')]
    private string $delivered = '0';

    #[ORM\Column(type: 'bigint')]
    private string $eventsFannedOut = '0';

    private function __construct()
    {
    }

    public function getWorkerId(): string { return $this->workerId; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
}
