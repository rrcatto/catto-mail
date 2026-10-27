<?php

declare(strict_types=1);

namespace App\Entity;

use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;

/**
 * A plan (`plans`, owner decision 2026-10-27): a named set of client limits, defined once.
 * Written and read with DBAL by App\Client\PlanCatalog; mapped for the schema model. The limit
 * columns are those of App\Entity\ClientLimits (NULL = no limit).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'plans')]
class Plan
{
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $apiRequestsPerMinute = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $validationJobsPerDay = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private int|string|null $validationAddressesPerDay = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private int|string|null $validationAddressesPerMonth = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sendJobsPerDay = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private int|string|null $sendRecipientsPerDay = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private int|string|null $sendRecipientsPerMonth = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxRecipientsPerSendJob = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxApiKeys = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxWebhookEndpoints = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxSendingDomains = null;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'text')]
        private string $id,
        #[ORM\Column(type: 'text')]
        private string $name,
    ) {
        $this->createdAt = $this->updatedAt = Clock::now();
    }

    public function getId(): string { return $this->id; }
    public function getName(): string { return $this->name; }
}
