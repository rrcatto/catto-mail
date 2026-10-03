<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Dashboard users by login email, matched case-insensitively (the
 * `users_email_uq` rule on lower(email)). Login identity only, never mailbox matching.
 *
 * @implements UserProviderInterface<User>
 */
final class DashboardUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findByEmail(string $email): ?User
    {
        return $this->em->createQuery('SELECT u FROM App\Entity\User u WHERE LOWER(u.email) = LOWER(:email)')
            ->setParameter('email', trim($email))
            ->getOneOrNullResult();
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->findByEmail($identifier) ?? throw new UserNotFoundException();
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }

        return $this->em->find(User::class, $user->getId()) ?? throw new UserNotFoundException();
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if ($user instanceof User) {
            $user->setPasswordHash($newHashedPassword);
            $this->em->flush();
        }
    }
}
