<?php

declare(strict_types=1);

namespace App\Entity;

use App\Tenant\TenantOwned;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Machine-client credential. Only sha256(raw key) as lower-case hex and a
 * non-secret display prefix are stored; the raw key exists only in the response
 * to its creation (App\Security\ApiKeyManager).
 */
#[ORM\Entity]
#[ORM\Table(name: 'api_keys')]
class ApiKey implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(name: 'client_id', nullable: false)]
        private Client $client,
        #[ORM\Column(type: 'text')]
        private string $keyHash,
        #[ORM\Column(type: 'text')]
        private string $keyPrefix,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $name = null,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getKeyHash(): string { return $this->keyHash; }
    public function getKeyPrefix(): string { return $this->keyPrefix; }
    public function getName(): ?string { return $this->name; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastUsedAt(): ?\DateTimeImmutable { return $this->lastUsedAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function isRevoked(): bool { return null !== $this->revokedAt; }

    public function revoke(): void
    {
        $this->revokedAt ??= Clock::now();
    }
}
