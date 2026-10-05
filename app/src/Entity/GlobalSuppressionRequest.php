<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GlobalSuppressionRequestOperation;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One accepted global opt-out request (D-38): the durable meaning of an
 * Idempotency-Key of a trusted client. It maps (client, operation, key) to the
 * canonical request hash, the suppression the request resulted in and the
 * original status (201 created, 200 already active). It never changes, so a
 * replay returns the same resource even after that suppression was lifted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'global_suppression_requests')]
class GlobalSuppressionRequest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(name: 'client_id', nullable: false)]
        private Client $client,
        #[ORM\Column(type: 'text', enumType: GlobalSuppressionRequestOperation::class)]
        private GlobalSuppressionRequestOperation $operation,
        #[ORM\Column(type: 'text')]
        private string $idempotencyKey,
        #[ORM\Column(type: 'text')]
        private string $requestHash,
        #[ORM\ManyToOne(targetEntity: Suppression::class)]
        #[ORM\JoinColumn(name: 'suppression_id', nullable: false)]
        private Suppression $suppression,
        #[ORM\Column(type: 'smallint')]
        private int $responseStatus,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $externalReference,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getOperation(): GlobalSuppressionRequestOperation { return $this->operation; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getRequestHash(): string { return $this->requestHash; }
    public function getSuppression(): Suppression { return $this->suppression; }
    public function getResponseStatus(): int { return $this->responseStatus; }
    public function getExternalReference(): ?string { return $this->externalReference; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
