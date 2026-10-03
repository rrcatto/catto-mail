<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ClientMembershipRole;
use App\Tenant\TenantOwned;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** A dashboard user's role within one client (D-12). */
#[ORM\Entity]
#[ORM\Table(name: 'client_memberships')]
class ClientMembership implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false)]
        private User $user,
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(name: 'client_id', nullable: false)]
        private Client $client,
        #[ORM\Column(type: 'text', enumType: ClientMembershipRole::class)]
        private ClientMembershipRole $role,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getClient(): Client { return $this->client; }
    public function getRole(): ClientMembershipRole { return $this->role; }
    public function setRole(ClientMembershipRole $role): void { $this->role = $role; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
