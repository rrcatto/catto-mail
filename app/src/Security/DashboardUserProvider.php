<?php

declare(strict_types=1);

namespace App\Security;

use App\Access\AccessControl;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Dashboard users by login email, matched case-insensitively (the
 * `users_email_uq` rule on lower(email)). Login identity only, never mailbox matching.
 * Every load and refresh (once per request) re-resolves the user's roles and
 * permission keys, so a change to roles or the ACL applies at the next request.
 *
 * @implements UserProviderInterface<User>
 */
final class DashboardUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessControl $access,
    ) {
    }

    public function findByEmail(string $email): ?User
    {
        return $this->em->createQuery('SELECT u FROM App\Entity\User u WHERE LOWER(u.email) = LOWER(:email)')
            ->setParameter('email', trim($email))
            ->getOneOrNullResult();
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->access->resolve($this->findByEmail($identifier) ?? throw new UserNotFoundException());
    }

    public function loadById(string $id): User
    {
        return $this->access->resolve($this->em->find(User::class, $id) ?? throw new UserNotFoundException());
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }

        $fresh = $this->em->find(User::class, $user->getId());
        if (null === $fresh || $fresh->isDisabled()) {
            throw new UserNotFoundException(); // a disabled user is signed out at the next request
        }

        return $this->access->resolve($fresh);
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }
}
