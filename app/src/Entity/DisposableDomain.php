<?php

declare(strict_types=1);

namespace App\Entity;

use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;

/** Global reference list of disposable-address domains (read by the validator). */
#[ORM\Entity]
#[ORM\Table(name: 'disposable_domains')]
class DisposableDomain
{
    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $addedAt;

    #[ORM\Column(type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $lastVerifiedAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'text')]
        private string $domain,
        #[ORM\Column(type: 'text')]
        private string $source,
    ) {
        $this->domain = strtolower($domain);
        $this->addedAt = Clock::now();
    }

    public function getDomain(): string { return $this->domain; }
    public function getSource(): string { return $this->source; }
    public function getAddedAt(): \DateTimeImmutable { return $this->addedAt; }
    public function getLastVerifiedAt(): ?\DateTimeImmutable { return $this->lastVerifiedAt; }
}
