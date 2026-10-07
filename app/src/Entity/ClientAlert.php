<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ClientAlertMetric;
use App\Enum\ClientAlertSeverity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An operator warning raised by the reputation evaluation (`client_alerts`, Phase 9).
 * Inferred risk, never an automatic action. Written with SQL by
 * App\Reputation\ReputationEvaluator and App\Reputation\AlertAdministration; read-only
 * in the ORM. Operator data only.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'client_alerts')]
class ClientAlert
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: false)]
    private Client $client;

    #[ORM\Column(type: 'text', enumType: ClientAlertMetric::class)]
    private ClientAlertMetric $metric;

    #[ORM\Column(type: 'smallint')]
    private int $windowHours;

    #[ORM\Column(type: 'text', enumType: ClientAlertSeverity::class)]
    private ClientAlertSeverity $severity;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $numerator;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $denominator;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4)]
    private string $value;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4)]
    private string $threshold;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $firstObservedAt;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $lastObservedAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'acknowledged_by', nullable: true)]
    private ?User $acknowledgedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $acknowledgementNote = null;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getMetric(): ClientAlertMetric { return $this->metric; }
    public function getSeverity(): ClientAlertSeverity { return $this->severity; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
}
