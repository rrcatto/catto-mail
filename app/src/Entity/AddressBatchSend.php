<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A send job created from an address batch (`address_batch_sends`, specification 2.11). A read-only mapping: the
 * services write this table with DBAL (App\System, App\AddressBatch).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'address_batch_sends')]
class AddressBatchSend
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'batch_id', type: 'uuid')]
    private Uuid $batchId;

    #[ORM\Column(name: 'send_job_id', type: 'uuid')]
    private Uuid $sendJobId;

    #[ORM\Column(name: 'stage', type: 'text')]
    private string $stage;

    #[ORM\Column(name: 'recipient_count', type: 'integer')]
    private int $recipientCount;

    #[ORM\Column(name: 'subject', type: 'text')]
    private string $subject;

    #[ORM\Column(name: 'created_by_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $createdByUserId = null;

    #[ORM\Column(name: 'created_at', type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getBatchId(): Uuid { return $this->batchId; }
    public function getSendJobId(): Uuid { return $this->sendJobId; }
    public function getStage(): string { return $this->stage; }
    public function getRecipientCount(): int { return $this->recipientCount; }
    public function getSubject(): string { return $this->subject; }
    public function getCreatedByUserId(): ?Uuid { return $this->createdByUserId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
