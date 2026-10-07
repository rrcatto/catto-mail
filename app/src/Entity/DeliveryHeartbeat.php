<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Status of one Go delivery daemon (Phase 8, written by Go): delivery state,
 * warm-up ceiling and the Postfix queue depth of the newest queue snapshot.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'delivery_heartbeats')]
class DeliveryHeartbeat
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

    #[ORM\Column(type: 'boolean')]
    private bool $liveDelivery;

    #[ORM\Column(type: 'boolean')]
    private bool $sendWorkHeld;

    #[ORM\Column(type: 'boolean')]
    private bool $outboundPaused;

    #[ORM\Column(type: 'integer')]
    private int $globalRatePerMinute;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $queueSnapshotAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $queueActive = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $queueDeferred = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $queueHold = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $queueIncoming = null;

    #[ORM\Column(type: 'bigint')]
    private string $submitted = '0';

    #[ORM\Column(type: 'bigint')]
    private string $temporaryFailures = '0';

    #[ORM\Column(type: 'bigint')]
    private string $dsnsProcessed = '0';

    private function __construct()
    {
    }

    public function getWorkerId(): string { return $this->workerId; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
}
