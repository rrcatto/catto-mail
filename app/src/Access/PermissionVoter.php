<?php

declare(strict_types=1);

namespace App\Access;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants the permission keys of PermissionCatalog to the signed-in dashboard user
 * whose roles hold them (resolved per request by DashboardUserProvider). A key
 * that is not in the catalogue is not supported here, so a mistyped key is denied
 * rather than granted by another voter. Disabled users get nothing.
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return PermissionCatalog::exists($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && !$user->isDisabled() && $user->hasPermission($attribute);
    }
}
