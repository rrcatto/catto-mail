<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Client;
use App\Entity\ClientMembership;
use App\Entity\User;
use App\Enum\ClientMembershipRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Dashboard access to a client's data (D-12, schema.md §3): through a membership
 * of the client, or as a global operator. Disabled users get nothing.
 *
 *   CLIENT_VIEW    viewer, member, admin
 *   CLIENT_OPERATE member, admin       (e.g. create jobs, manage sending domains)
 *   CLIENT_ADMIN   admin               (API keys, webhook endpoints, memberships)
 *
 * @extends Voter<string, Client>
 */
final class ClientVoter extends Voter
{
    public const VIEW = 'CLIENT_VIEW';
    public const OPERATE = 'CLIENT_OPERATE';
    public const ADMIN = 'CLIENT_ADMIN';

    private const ALLOWED = [
        self::VIEW => [ClientMembershipRole::Viewer, ClientMembershipRole::Member, ClientMembershipRole::Admin],
        self::OPERATE => [ClientMembershipRole::Member, ClientMembershipRole::Admin],
        self::ADMIN => [ClientMembershipRole::Admin],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return isset(self::ALLOWED[$attribute]) && $subject instanceof Client;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || $user->isDisabled()) {
            return false;
        }
        if ($user->isOperator()) {
            return true;
        }
        $membership = $this->em->getRepository(ClientMembership::class)->findOneBy(['user' => $user, 'client' => $subject]);

        return null !== $membership && \in_array($membership->getRole(), self::ALLOWED[$attribute], true);
    }
}
