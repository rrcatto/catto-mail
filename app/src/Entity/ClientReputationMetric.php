<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Per-client transport facts of the last reputation evaluation in one window
 * (`client_reputation_metrics`, Phase 9). Written by App\Reputation\ReputationEvaluator
 * with SQL; read-only in the ORM. Operator data only.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'client_reputation_metrics')]
class ClientReputationMetric
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: false)]
    private Client $client;

    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    private int $windowHours;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $computedAt;

    #[ORM\Column(type: 'bigint')]
    private int|string $messagesSubmitted;

    #[ORM\Column(type: 'bigint')]
    private int|string $validationAddresses;

    #[ORM\Column(type: 'bigint')]
    private int|string $hardBounces;

    #[ORM\Column(type: 'bigint')]
    private int|string $softBounces;

    #[ORM\Column(type: 'bigint')]
    private int|string $deferrals;

    #[ORM\Column(type: 'bigint')]
    private int|string $providerPolicyFailures;

    #[ORM\Column(type: 'bigint')]
    private int|string $complaints;

    #[ORM\Column(type: 'bigint')]
    private int|string $suppressed;

    #[ORM\Column(type: 'bigint')]
    private int|string $outcomeUnknown;

    #[ORM\Column(type: 'bigint')]
    private int|string $webhookFailures;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $previousDailyAverage = null;

    private function __construct()
    {
    }

    public function getClient(): Client { return $this->client; }
    public function getWindowHours(): int { return $this->windowHours; }
    public function getComputedAt(): \DateTimeImmutable { return $this->computedAt; }
    public function getMessagesSubmitted(): int { return (int) $this->messagesSubmitted; }
    public function getHardBounces(): int { return (int) $this->hardBounces; }
    public function getComplaints(): int { return (int) $this->complaints; }
}
