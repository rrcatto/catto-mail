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
 * of the client, or through an installation-wide permission
 * (PLATFORM.CLIENT.VIEW reads any client, PLATFORM.CLIENT.MANAGE also acts in
 * any client). Disabled users get nothing.
 *
 *   CLIENT_VIEW    viewer, member, admin   or PLATFORM.CLIENT.VIEW / PLATFORM.CLIENT.MANAGE
 *   CLIENT_OPERATE member, admin           or PLATFORM.CLIENT.MANAGE (e.g. sending-domain checks)
 *   CLIENT_ADMIN   admin                   or PLATFORM.CLIENT.MANAGE (e.g. lifting the client's opt-outs,
 *                                                                        accepting the service policy)
 *   CLIENT_KEYS    admin                   or PLATFORM.CLIENT_KEY.MANAGE (create/revoke the client's API
 *                                                                        keys; Phase 9)
 *
 * @extends Voter<string, Client>
 */
final class ClientVoter extends Voter
{
    public const VIEW = 'CLIENT_VIEW';
    public const OPERATE = 'CLIENT_OPERATE';
    public const ADMIN = 'CLIENT_ADMIN';
    public const KEYS = 'CLIENT_KEYS';

    private const ALLOWED = [
        self::VIEW => [ClientMembershipRole::Viewer, ClientMembershipRole::Member, ClientMembershipRole::Admin],
        self::OPERATE => [ClientMembershipRole::Member, ClientMembershipRole::Admin],
        self::ADMIN => [ClientMembershipRole::Admin],
        self::KEYS => [ClientMembershipRole::Admin],
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
        $platform = self::KEYS === $attribute
            ? $user->hasPermission('PLATFORM.CLIENT_KEY.MANAGE')
            : ($user->hasPermission('PLATFORM.CLIENT.MANAGE') || (self::VIEW === $attribute && $user->hasPermission('PLATFORM.CLIENT.VIEW')));
        if ($platform) {
            return true;
        }
        $membership = $this->em->getRepository(ClientMembership::class)->findOneBy(['user' => $user, 'client' => $subject]);

        return null !== $membership && \in_array($membership->getRole(), self::ALLOWED[$attribute], true);
    }
}
