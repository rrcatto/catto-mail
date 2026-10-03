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
use App\Enum\GlobalRole;
use App\Security\DashboardUserProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Administrative changes to clients, dashboard users and client memberships
 * (D-12). Every change is audited; passwords are hashed with Symfony's
 * PasswordHasher and never logged.
 */
final class AccountAdministration
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly DashboardUserProvider $users,
    ) {
    }

    public function createClient(string $companyName, string $contactEmail, string $plan, ClientStatus $status, AuditActor $actor): Client
    {
        if ('' === trim($companyName) || '' === trim($plan) || !filter_var(trim($contactEmail), \FILTER_VALIDATE_EMAIL)) {
            throw new DomainRuleViolation('A client needs a company name, a plan and a valid contact email.');
        }
        $client = new Client(trim($companyName), trim($contactEmail), trim($plan));
        $client->setStatus($status);

        return $this->em->wrapInTransaction(function () use ($client, $actor): Client {
            $this->em->persist($client);
            $this->em->flush();
            $this->audit->record($actor, 'client.created', 'client', $client->getId()->toRfc4122(),
                ['company_name' => $client->getCompanyName(), 'status' => $client->getStatus()->value, 'plan' => $client->getPlan()]);

            return $client;
        });
    }

    public function setClientStatus(Client $client, ClientStatus $status, AuditActor $actor): void
    {
        $from = $client->getStatus();
        if ($from === $status) {
            return;
        }
        $this->em->wrapInTransaction(function () use ($client, $status, $from, $actor): void {
            $client->setStatus($status);
            $this->em->flush();
            $this->audit->record($actor, 'client.status_changed', 'client', $client->getId()->toRfc4122(),
                ['from' => $from->value, 'to' => $status->value]);
        });
    }

    /** Login emails are unique case-insensitively (users_email_uq). */
    public function createUser(string $email, ?string $displayName, ?string $plainPassword, bool $operator, AuditActor $actor): User
    {
        $email = trim($email);
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new DomainRuleViolation('The login email is not valid.');
        }
        if (null !== $this->users->findByEmail($email)) {
            throw new DomainRuleViolation('A user with this login email already exists (emails are case-insensitive).');
        }
        $user = new User($email, $displayName);
        $user->setGlobalRole($operator ? GlobalRole::Operator : null);
        if (null !== $plainPassword) {
            $this->assertPassword($plainPassword);
            $user->setPasswordHash($this->hasher->hashPassword($user, $plainPassword));
        }

        return $this->em->wrapInTransaction(function () use ($user, $actor): User {
            $this->em->persist($user);
            $this->em->flush();
            $this->audit->record($actor, 'user.created', 'user', $user->getId()->toRfc4122(),
                ['email' => $user->getEmail(), 'global_role' => $user->getGlobalRole()?->value, 'has_password' => null !== $user->getPassword()]);

            return $user;
        });
    }

    public function setPassword(User $user, string $plainPassword, AuditActor $actor): void
    {
        $this->assertPassword($plainPassword);
        $this->em->wrapInTransaction(function () use ($user, $plainPassword, $actor): void {
            $user->setPasswordHash($this->hasher->hashPassword($user, $plainPassword));
            $this->em->flush();
            $this->audit->record($actor, 'user.password_set', 'user', $user->getId()->toRfc4122());
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

    public function setOperator(User $user, bool $operator, AuditActor $actor): void
    {
        if ($user->isOperator() === $operator) {
            return;
        }
        $this->em->wrapInTransaction(function () use ($user, $operator, $actor): void {
            $user->setGlobalRole($operator ? GlobalRole::Operator : null);
            $this->em->flush();
            $this->audit->record($actor, 'user.global_role_changed', 'user', $user->getId()->toRfc4122(),
                ['global_role' => $user->getGlobalRole()?->value]);
        });
    }

    /** Adds the membership or changes its role (memberships are not deleted: no DELETE grant). */
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

    private function assertPassword(string $plain): void
    {
        if (mb_strlen($plain) < 12 || mb_strlen($plain) > 4096) {
            throw new DomainRuleViolation('Passwords must be 12 to 4096 characters long.');
        }
    }
}
