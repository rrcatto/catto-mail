<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiKey;
use App\Entity\Client;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Security user of the stateless /v1 firewall: one authenticated API key and the
 * client it belongs to. This is the only source of the tenant for API requests.
 */
final class ApiClientUser implements UserInterface
{
    public function __construct(private readonly ApiKey $apiKey)
    {
    }

    public function getApiKey(): ApiKey { return $this->apiKey; }
    public function getClient(): Client { return $this->apiKey->getClient(); }

    public function getRoles(): array
    {
        return ['ROLE_API_CLIENT'];
    }

    public function getUserIdentifier(): string
    {
        return 'api-key:'.$this->apiKey->getId()->toRfc4122();
    }

    public function eraseCredentials(): void
    {
    }
}
