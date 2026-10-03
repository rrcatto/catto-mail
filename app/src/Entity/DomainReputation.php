<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Per-destination-domain metrics maintained by Go (client NULL = platform-wide). */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'domain_reputation')]
class DomainReputation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: true)]
    private ?Client $client = null;

    #[ORM\Column(type: 'text')]
    private string $destinationDomain;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'metrics_json', type: 'jsonb_map')]
    private array $metrics = [];

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $lastUpdatedAt;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): ?Client { return $this->client; }
    public function getDestinationDomain(): string { return $this->destinationDomain; }
    public function getMetrics(): array { return $this->metrics; }
    public function getLastUpdatedAt(): \DateTimeImmutable { return $this->lastUpdatedAt; }
}
