<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuditActorType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Append-only security audit record, written via App\Audit\AuditLogger. */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'audit_log')]
class AuditLogEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'text', enumType: AuditActorType::class)]
    private AuditActorType $actorType;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $actorId = null;

    #[ORM\Column(type: 'text')]
    private string $action;

    #[ORM\Column(type: 'text')]
    private string $targetType;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $targetId = null;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'detail_json', type: 'jsonb_map')]
    private array $detail = [];

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $occurredAt;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getActorType(): AuditActorType { return $this->actorType; }
    public function getActorId(): ?string { return $this->actorId; }
    public function getAction(): string { return $this->action; }
    public function getTargetType(): string { return $this->targetType; }
    public function getTargetId(): ?string { return $this->targetId; }
    public function getDetail(): array { return $this->detail; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
