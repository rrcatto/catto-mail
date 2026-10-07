<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuotaMetric;
use App\Enum\QuotaPeriod;
use App\Tenant\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Admitted-work counter (`client_quota_usage`, Phase 9). Written only by
 * App\Client\QuotaEnforcer with one atomic INSERT ... ON CONFLICT DO UPDATE in the
 * transaction that admits the work; read-only in the ORM.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'client_quota_usage')]
class ClientQuotaUsage implements TenantOwned
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: false)]
    private Client $client;

    #[ORM\Id]
    #[ORM\Column(type: 'text', enumType: QuotaMetric::class)]
    private QuotaMetric $metric;

    #[ORM\Id]
    #[ORM\Column(type: 'text', enumType: QuotaPeriod::class)]
    private QuotaPeriod $period;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(type: 'bigint')]
    private int|string $used;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $updatedAt;

    private function __construct()
    {
    }

    public function getClient(): Client { return $this->client; }
    public function getMetric(): QuotaMetric { return $this->metric; }
    public function getPeriod(): QuotaPeriod { return $this->period; }
    public function getPeriodStart(): \DateTimeImmutable { return $this->periodStart; }
    public function getUsed(): int { return (int) $this->used; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
