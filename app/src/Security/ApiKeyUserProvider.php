<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Provider of the stateless API firewall. Users are created only by
 * App\Security\ApiKeyAuthenticator from a presented key, never loaded by name.
 *
 * @implements UserProviderInterface<ApiClientUser>
 */
final class ApiKeyUserProvider implements UserProviderInterface
{
    public function refreshUser(UserInterface $user): UserInterface
    {
        throw new UnsupportedUserException('The API firewall is stateless.');
    }

    public function supportsClass(string $class): bool
    {
        return ApiClientUser::class === $class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        throw new UserNotFoundException('API clients authenticate with a key only.');
    }
}
