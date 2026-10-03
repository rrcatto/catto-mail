<?php

declare(strict_types=1);

namespace App\Entity;

use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** One accepted recipient-upload request (D-24); carries the batch Idempotency-Key. */
#[ORM\Entity]
#[ORM\Table(name: 'send_job_recipient_batches')]
class SendJobRecipientBatch
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: SendJob::class)]
        #[ORM\JoinColumn(name: 'send_job_id', nullable: false)]
        private SendJob $sendJob,
        #[ORM\Column(type: 'text')]
        private string $idempotencyKey,
        #[ORM\Column(type: 'text')]
        private string $requestHash,
        #[ORM\Column(type: 'integer')]
        private int $recipientCount,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getSendJob(): SendJob { return $this->sendJob; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getRequestHash(): string { return $this->requestHash; }
    public function getRecipientCount(): int { return $this->recipientCount; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
