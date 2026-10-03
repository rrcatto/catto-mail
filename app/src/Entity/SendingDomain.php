<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DkimStatus;
use App\Enum\SendingDomainStatus;
use App\Tenant\TenantOwned;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Client sending domain with DNS TXT verification and DKIM status (D-13, D-26).
 * TXT record `_smarthost-verification.<domain>` = `smarthost-verification=<token>`.
 * The token is published in DNS, is not a secret, and is stored as-is.
 * State changes go through App\Domain\SendingDomainService.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sending_domains')]
class SendingDomain implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'text', enumType: SendingDomainStatus::class)]
    private SendingDomainStatus $status = SendingDomainStatus::Pending;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastCheckError = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $dkimSelector = null;

    #[ORM\Column(type: 'text', enumType: DkimStatus::class)]
    private DkimStatus $dkimStatus = DkimStatus::NotConfigured;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(name: 'client_id', nullable: false)]
        private Client $client,
        #[ORM\Column(type: 'text')]
        private string $domain,
        #[ORM\Column(type: 'text')]
        private string $verificationToken,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = $this->updatedAt = Clock::now();
    }

    public function getId(): Uuid { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getDomain(): string { return $this->domain; }
    public function getStatus(): SendingDomainStatus { return $this->status; }
    public function getVerificationToken(): string { return $this->verificationToken; }
    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }
    public function getLastCheckedAt(): ?\DateTimeImmutable { return $this->lastCheckedAt; }
    public function getLastCheckError(): ?string { return $this->lastCheckError; }
    public function getDkimSelector(): ?string { return $this->dkimSelector; }
    public function getDkimStatus(): DkimStatus { return $this->dkimStatus; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function isVerified(): bool { return SendingDomainStatus::Verified === $this->status; }
    public function isDisabled(): bool { return SendingDomainStatus::Disabled === $this->status; }

    public function markVerified(\DateTimeImmutable $at): void
    {
        $this->status = SendingDomainStatus::Verified;
        $this->verifiedAt ??= $at;
        $this->lastCheckedAt = $at;
        $this->lastCheckError = null;
        $this->updatedAt = $at;
    }

    public function recordFailedCheck(\DateTimeImmutable $at, string $error): void
    {
        $this->lastCheckedAt = $at;
        $this->lastCheckError = $error;
        $this->updatedAt = $at;
    }

    public function disable(): void
    {
        $this->status = SendingDomainStatus::Disabled;
        $this->updatedAt = Clock::now();
    }

    /** Re-enable a disabled domain; it must pass the TXT challenge again. */
    public function reenable(): void
    {
        $this->status = SendingDomainStatus::Pending;
        $this->verifiedAt = null;
        $this->updatedAt = Clock::now();
    }

    public function setDkim(DkimStatus $status, ?string $selector): void
    {
        $this->dkimStatus = $status;
        $this->dkimSelector = $selector;
        $this->updatedAt = Clock::now();
    }
}
