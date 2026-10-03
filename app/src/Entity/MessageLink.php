<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Server-side click map written by Go (D-09); read by the tracking endpoint (Phase 6). */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'message_links')]
class MessageLink
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(name: 'message_id', nullable: false)]
    private Message $message;

    #[ORM\Column(type: 'integer')]
    private int $linkIndex;

    #[ORM\Column(type: 'text')]
    private string $targetUrl;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getMessage(): Message { return $this->message; }
    public function getLinkIndex(): int { return $this->linkIndex; }
    public function getTargetUrl(): string { return $this->targetUrl; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
