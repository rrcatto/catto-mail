<?php

declare(strict_types=1);

namespace App\Entity;

use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** A private operator note about a client account (`client_notes`, Phase 9). Never shown to the client. */
#[ORM\Entity]
#[ORM\Table(name: 'client_notes')]
class ClientNote
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
        #[ORM\Column(type: 'text')]
        private string $note,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'author_user_id', nullable: true)]
        private ?User $author = null,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getNote(): string { return $this->note; }
    public function getAuthor(): ?User { return $this->author; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
