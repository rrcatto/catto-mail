<?php

declare(strict_types=1);

namespace App\Client;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\ClientMembership;
use App\Entity\User;
use App\Enum\ClientMembershipRole;
use App\Enum\ClientStatus;
use App\Security\DashboardUserProvider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Administrative changes to clients, dashboard users and client memberships
 * (D-12). Every change is audited. Client status changes belong to the lifecycle
 * (App\Client\ClientLifecycle::changeStatus, with a reason and the transition rules). There are no passwords: users sign in with
 * emailed single-use links (App\Security\LoginLinkService); installation-wide
 * roles are managed by App\Access\AccessControl.
 */
final class AccountAdministration
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly DashboardUserProvider $users,
        private readonly ClientLifecycle $lifecycle,
    ) {
    }

    /**
     * Creates a client record (App\Client\ClientLifecycle::create). A client created active
     * by an operator is an internal client: it is approved at once and exempt from policy
     * acceptance. A pending_approval client goes through the approval workflow.
     */
    public function createClient(string $companyName, string $contactEmail, string $plan, ClientStatus $status, AuditActor $actor): Client
    {
        return $this->lifecycle->create($companyName, $contactEmail, $plan, $status, $actor, ClientStatus::PendingApproval === $status);
    }

    /** Login emails are unique case-insensitively (users_email_uq). */
    public function createUser(string $email, ?string $displayName, AuditActor $actor): User
    {
        $email = trim($email);
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 320) {
            throw new DomainRuleViolation('The login email is not valid.');
        }
        if (null !== $this->users->findByEmail($email)) {
            throw new DomainRuleViolation('A user with this login email already exists (emails are case-insensitive).');
        }
        $user = new User($email, null === $displayName || '' === trim($displayName) ? null : trim($displayName));

        return $this->em->wrapInTransaction(function () use ($user, $actor): User {
            $this->em->persist($user);
            $this->em->flush();
            $this->audit->record($actor, 'user.created', 'user', $user->getId()->toRfc4122(), ['email' => $user->getEmail()]);

            return $user;
        });
    }

    public function setDisplayName(User $user, ?string $displayName, AuditActor $actor): void
    {
        $before = $user->getDisplayName();
        $user->setDisplayName($displayName);
        if ($before === $user->getDisplayName()) {
            return;
        }
        $this->em->wrapInTransaction(function () use ($user, $before, $actor): void {
            $this->em->flush();
            $this->audit->record($actor, 'user.display_name_changed', 'user', $user->getId()->toRfc4122(),
                ['before' => $before, 'after' => $user->getDisplayName()]);
        });
    }

    public function setDisabled(User $user, bool $disabled, AuditActor $actor): void
    {
        if ($user->isDisabled() === $disabled) {
            return;
        }
        $this->em->wrapInTransaction(function () use ($user, $disabled, $actor): void {
            $disabled ? $user->disable() : $user->enable();
            $this->em->flush();
            $this->audit->record($actor, $disabled ? 'user.disabled' : 'user.enabled', 'user', $user->getId()->toRfc4122());
        });
    }

    /** Adds the membership or changes its role. */
    public function setMembership(User $user, Client $client, ClientMembershipRole $role, AuditActor $actor): ClientMembership
    {
        return $this->em->wrapInTransaction(function () use ($user, $client, $role, $actor): ClientMembership {
            $membership = $this->em->getRepository(ClientMembership::class)->findOneBy(['user' => $user, 'client' => $client]);
            if (null === $membership) {
                $membership = new ClientMembership($user, $client, $role);
                $this->em->persist($membership);
                $action = 'client_membership.created';
            } elseif ($membership->getRole() !== $role) {
                $membership->setRole($role);
                $action = 'client_membership.role_changed';
            } else {
                return $membership;
            }
            $this->em->flush();
            $this->audit->record($actor, $action, 'client_membership', $membership->getId()->toRfc4122(), [
                'user_id' => $user->getId()->toRfc4122(), 'client_id' => $client->getId()->toRfc4122(), 'role' => $role->value]);

            return $membership;
        });
    }

    /** Removes the user's membership of the client (audited); false when there was none. */
    public function removeMembership(User $user, Client $client, AuditActor $actor): bool
    {
        $membership = $this->em->getRepository(ClientMembership::class)->findOneBy(['user' => $user, 'client' => $client]);
        if (null === $membership) {
            return false;
        }

        return $this->em->wrapInTransaction(function () use ($membership, $user, $client, $actor): bool {
            $id = $membership->getId()->toRfc4122();
            $role = $membership->getRole()->value;
            $this->em->remove($membership);
            $this->em->flush();
            $this->audit->record($actor, 'client_membership.removed', 'client_membership', $id, [
                'user_id' => $user->getId()->toRfc4122(), 'client_id' => $client->getId()->toRfc4122(), 'role' => $role]);

            return true;
        });
    }
}
