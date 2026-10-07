<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UsageType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** One usage category's quantity on a billing statement (`billing_statement_lines`, Phase 9). */
#[ORM\Entity]
#[ORM\Table(name: 'billing_statement_lines')]
class BillingStatementLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'bigint')]
    private int|string $quantity;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: BillingStatement::class)]
        #[ORM\JoinColumn(name: 'statement_id', nullable: false)]
        private BillingStatement $statement,
        #[ORM\Column(type: 'text', enumType: UsageType::class)]
        private UsageType $usageType,
        int $quantity,
    ) {
        $this->id = Uuid::v7();
        $this->quantity = $quantity;
    }

    public function getId(): Uuid { return $this->id; }
    public function getStatement(): BillingStatement { return $this->statement; }
    public function getUsageType(): UsageType { return $this->usageType; }
    public function getQuantity(): int { return (int) $this->quantity; }

    /** Re-preparing a draft statement recomputes its quantities. */
    public function setQuantity(int $quantity): void { $this->quantity = $quantity; }
}
