<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Disabled users and invited users without a password cannot log in or stay logged in. */
final class DashboardUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }
        if ($user->isDisabled()) {
            throw new CustomUserMessageAccountStatusException('This account is disabled.');
        }
        if (null === $user->getPassword()) {
            throw new CustomUserMessageAccountStatusException('This account has no password yet.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && $user->isDisabled()) {
            throw new CustomUserMessageAccountStatusException('This account is disabled.');
        }
    }
}
