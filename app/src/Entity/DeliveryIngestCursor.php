<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Go's persistent ingestion position (D-16): log generation identity plus the
 * position within it. Internal to Go; Symfony's role has no privilege on it.
 * Mapped only so that the ORM model covers the whole schema.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'delivery_ingest_cursors')]
class DeliveryIngestCursor
{
    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private string $source;

    #[ORM\Column(type: 'text')]
    private string $generationId;

    #[ORM\Column(type: 'bigint')]
    private int|string $position;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $updatedAt;

    private function __construct()
    {
    }

    public function getSource(): string { return $this->source; }
    public function getGenerationId(): string { return $this->generationId; }
    public function getPosition(): int { return (int) $this->position; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
