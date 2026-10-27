<?php

declare(strict_types=1);

namespace App\Client;

use App\Audit\AuditActor;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\User;
use App\Enum\ClientMembershipRole;
use App\Enum\ClientOrigin;
use App\Enum\ClientStatus;
use App\Security\DashboardUserProvider;
use App\Security\LoginLinkService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An operator opens an account (owner decision 2026-10-27, replacing "create a pending client,
 * approve it, add yourself under Users"): the organisation's name, the address of the person who
 * manages it, a plan and the service policy, in one step.
 *
 *   forManager   the manager becomes the account admin (their user is created when it does not
 *                exist) and is emailed a sign-in link to the account. Policy "exempt": the account
 *                is active at once. "must accept": while a policy version is in force the account
 *                waits for the manager's acceptance and then opens by itself
 *                (ClientLifecycle::openAfterAcceptance); there is nothing for the operator to
 *                approve, because creating it was the approval.
 *   forOperator  an operator who is signed in without an account opens one for themselves from
 *                the connect page (specification 2.13): plan Standard, exempt, no email.
 *
 * Everything is one transaction and audited by the services it calls; the email follows the
 * commit and its failure never undoes the account.
 */
final class AccountOpening
{
    public const MUST_ACCEPT = 'must_accept';
    public const EXEMPT = 'exempt';

    public function __construct(
        private readonly ClientLifecycle $lifecycle,
        private readonly AccountAdministration $accounts,
        private readonly PlanCatalog $plans,
        private readonly DashboardUserProvider $users,
        private readonly LoginLinkService $links,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{client: Client, mailed: bool} whether the manager's email went out
     */
    public function forManager(string $organisation, string $managerEmail, string $plan, string $policy, string $note, User $operator): array
    {
        if (!\in_array($policy, [self::MUST_ACCEPT, self::EXEMPT], true)) {
            throw new DomainRuleViolation('Choose whether the manager must accept the service policy.');
        }
        $mustAccept = self::MUST_ACCEPT === $policy && null !== $this->lifecycle->policyVersionInForce();
        $client = $this->open($organisation, $managerEmail, $plan, self::MUST_ACCEPT === $policy, $mustAccept, $note, $operator);
        $mailed = true;
        try {
            $this->links->welcome($this->manager($client), $client->getCompanyName(), '/dashboard/c/'.$client->getId()->toRfc4122(), $mustAccept);
        } catch (\RuntimeException) {
            $mailed = false;
        }

        return ['client' => $client, 'mailed' => $mailed];
    }

    public function forOperator(string $organisation, User $operator): Client
    {
        return $this->open($organisation, $operator->getEmail(), PlanCatalog::STANDARD, false, false, '', $operator);
    }

    private function open(string $organisation, string $email, string $plan, bool $policyRequired, bool $waitForAcceptance, string $note, User $operator): Client
    {
        // Every refusal before the transaction: one inside it would close the entity manager.
        $email = trim($email);
        if ('' === $email || mb_strlen($email) > 320 || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new DomainRuleViolation('Give the email address of the person who manages the account.');
        }
        if (null === $this->plans->find($plan)) {
            throw new DomainRuleViolation('Choose one of the plans.');
        }
        $existing = $this->users->findByEmail($email);
        if (null !== $existing && $existing->isDisabled()) {
            throw new DomainRuleViolation(\sprintf('%s belongs to a disabled user: enable them under Access › Users first.', $email));
        }
        if (mb_strlen(trim($note)) > 4000) {
            throw new DomainRuleViolation('A note has at most 4000 characters.');
        }
        $actor = AuditActor::user($operator);

        return $this->em->getConnection()->transactional(function () use ($organisation, $email, $plan, $policyRequired, $waitForAcceptance, $note, $operator, $existing, $actor): Client {
            $client = $this->lifecycle->create($organisation, $email, $plan, $waitForAcceptance ? ClientStatus::PendingApproval : ClientStatus::Active,
                $actor, $policyRequired, ClientOrigin::Operator, 'opened by an operator for its manager', $waitForAcceptance);
            $user = $existing ?? $this->accounts->createUser($email, null, $actor);
            $this->accounts->setMembership($user, $client, ClientMembershipRole::Admin, $actor);
            $this->plans->assign($client, $plan, $actor);
            if ('' !== trim($note)) {
                $this->lifecycle->addNote($client, $note, $actor, $operator);
            }

            return $client;
        });
    }

    private function manager(Client $client): User
    {
        return $this->users->findByEmail($client->getContactEmail()) ?? throw new \LogicException('The manager of a new account has no user.');
    }
}
