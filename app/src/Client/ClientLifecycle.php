<?php

declare(strict_types=1);

namespace App\Client;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\ClientNote;
use App\Entity\ClientPolicyAcceptance;
use App\Entity\User;
use App\Enum\ClientOrigin;
use App\Enum\ClientStatus;
use App\Enum\PolicyAcceptanceSource;
use App\Util\Clock;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The external-client account lifecycle (Phase 9, specification 2.10 `client_lifecycle`).
 *
 *   create          an operator creates a client record: pending_approval (default), or
 *                   active for an internal client created by an operator (approved at once);
 *   apply           a public application record (origin public_application): only while
 *                   APP_PUBLIC_ONBOARDING_ENABLED is true, always pending_approval, no user,
 *                   no key - an operator reviews and approves it. No route calls it in this
 *                   release; it is the single entry point a future public channel must use;
 *   changeStatus    approve, throttle, suspend, lift a throttle, reactivate, close
 *                   (ClientStatusTransitions), always with an operator note, audited with
 *                   the actor; the client row is locked so concurrent changes serialise;
 *   approval rule   when the client requires policy acceptance and a policy version is in
 *                   force (APP_ACCEPTABLE_USE_POLICY_VERSION), approval needs a recorded
 *                   acceptance of that version;
 *   account         contacts, plan and the policy requirement (audited), private operator
 *                   notes (client_notes, never shown to the client), policy acceptances.
 */
final class ClientLifecycle
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly bool $publicOnboardingEnabled,
        private readonly string $policyVersion,
    ) {
    }

    public function publicOnboardingEnabled(): bool
    {
        return $this->publicOnboardingEnabled;
    }

    /** The service-policy version in force, or null when none is configured. */
    public function policyVersionInForce(): ?string
    {
        return '' === trim($this->policyVersion) ? null : trim($this->policyVersion);
    }

    public function create(string $companyName, string $contactEmail, string $plan, ClientStatus $status, AuditActor $actor,
        bool $policyAcceptanceRequired = true, ?string $billingContactEmail = null, ?string $abuseContactEmail = null,
        ClientOrigin $origin = ClientOrigin::Operator, string $note = ''): Client
    {
        if (!\in_array($status, [ClientStatus::PendingApproval, ClientStatus::Active], true)) {
            throw new DomainRuleViolation('A new client starts pending_approval, or active when an operator creates an internal client.');
        }
        if (ClientOrigin::PublicApplication === $origin && ClientStatus::PendingApproval !== $status) {
            throw new DomainRuleViolation('A public application always starts pending_approval.');
        }
        [$companyName, $contactEmail, $billingContactEmail, $abuseContactEmail, $plan] =
            self::accountFields($companyName, $contactEmail, $billingContactEmail, $abuseContactEmail, $plan);
        if (ClientStatus::Active === $status && $policyAcceptanceRequired && null !== $this->policyVersionInForce()) {
            throw new DomainRuleViolation(\sprintf('Policy version %s must be accepted before the client is approved: create it pending_approval, or exempt this internal client from policy acceptance.',
                $this->policyVersionInForce()));
        }
        $client = new Client($companyName, $contactEmail, $plan);
        $client->updateAccount($companyName, $contactEmail, $billingContactEmail, $abuseContactEmail, $plan);
        $client->setOrigin($origin);
        $client->setPolicyAcceptanceRequired($policyAcceptanceRequired);

        return $this->em->wrapInTransaction(function () use ($client, $status, $actor, $note): Client {
            if (ClientStatus::Active === $status) {
                $client->transitionTo(ClientStatus::Active, $client->getCreatedAt());
            }
            $this->em->persist($client);
            $this->em->flush();
            $id = $client->getId()->toRfc4122();
            $this->audit->record($actor, 'client.created', 'client', $id, [
                'company_name' => $client->getCompanyName(), 'status' => $client->getStatus()->value, 'plan' => $client->getPlan(),
                'origin' => $client->getOrigin()->value, 'policy_acceptance_required' => $client->isPolicyAcceptanceRequired()]);
            if (ClientStatus::Active === $status) {
                $this->audit->record($actor, 'client.approved', 'client', $id,
                    ['from' => null, 'to' => 'active', 'note' => '' === trim($note) ? 'created active by an operator (internal client)' : trim($note)]);
            }

            return $client;
        });
    }

    /** A public application (gated by APP_PUBLIC_ONBOARDING_ENABLED); never credentials or approval. */
    public function apply(string $companyName, string $contactEmail, ?string $abuseContactEmail = null): Client
    {
        if (!$this->publicOnboardingEnabled) {
            throw new DomainRuleViolation('Public onboarding is disabled (APP_PUBLIC_ONBOARDING_ENABLED=false).');
        }

        return $this->create($companyName, $contactEmail, 'application', ClientStatus::PendingApproval,
            AuditActor::system('public_application'), true, null, $abuseContactEmail, ClientOrigin::PublicApplication);
    }

    /**
     * A lifecycle transition with the operator's reason. When $operator is given (dashboard,
     * console --operator) they must hold the permission the transition needs.
     */
    public function changeStatus(Client $client, ClientStatus $to, AuditActor $actor, string $note, ?User $operator = null): void
    {
        $note = trim($note);
        if (mb_strlen($note) < 3 || mb_strlen($note) > 1000) {
            throw new DomainRuleViolation('Record a reason (3-1000 characters) for every client status change.');
        }
        // Rules are checked before the transaction (a refusal must not close the entity
        // manager) and again under the client row lock (a concurrent change wins once).
        $from = $client->getStatus();
        $this->assertTransition($client, $from, $to, $operator);
        $this->em->wrapInTransaction(function () use ($client, $from, $to, $actor, $note, $operator): void {
            $this->em->refresh($client, LockMode::PESSIMISTIC_WRITE);
            if ($client->getStatus() !== $from) {
                throw new DomainRuleViolation(\sprintf('The client changed to %s meanwhile; review it and try again.', $client->getStatus()->value));
            }
            $this->assertTransition($client, $from, $to, $operator);
            $client->transitionTo($to, Clock::now());
            $this->em->flush();
            $this->audit->record($actor, self::auditAction($from, $to), 'client', $client->getId()->toRfc4122(),
                ['from' => $from->value, 'to' => $to->value, 'note' => $note]);
        });
    }

    public function updateAccount(Client $client, string $companyName, string $contactEmail, ?string $billingContactEmail,
        ?string $abuseContactEmail, string $plan, AuditActor $actor): bool
    {
        [$companyName, $contactEmail, $billingContactEmail, $abuseContactEmail, $plan] =
            self::accountFields($companyName, $contactEmail, $billingContactEmail, $abuseContactEmail, $plan);
        $before = self::account($client);
        $client->updateAccount($companyName, $contactEmail, $billingContactEmail, $abuseContactEmail, $plan);
        $after = self::account($client);
        if ($before === $after) {
            return false;
        }
        $this->em->wrapInTransaction(function () use ($client, $before, $after, $actor): void {
            $this->em->flush();
            $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));
            $this->audit->record($actor, 'client.account_updated', 'client', $client->getId()->toRfc4122(), [
                'before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))]);
        });

        return true;
    }

    public function setPolicyAcceptanceRequired(Client $client, bool $required, AuditActor $actor, string $note): bool
    {
        if ($client->isPolicyAcceptanceRequired() === $required) {
            return false;
        }
        if (mb_strlen(trim($note)) < 3) {
            throw new DomainRuleViolation('Record a reason (at least 3 characters) for changing the policy requirement.');
        }
        $this->em->wrapInTransaction(function () use ($client, $required, $actor, $note): void {
            $client->setPolicyAcceptanceRequired($required);
            $this->em->flush();
            $this->audit->record($actor, 'client.policy_requirement_changed', 'client', $client->getId()->toRfc4122(),
                ['policy_acceptance_required' => $required, 'note' => trim($note)]);
        });

        return true;
    }

    public function addNote(Client $client, string $note, AuditActor $actor, ?User $author = null): ClientNote
    {
        $note = trim($note);
        if ('' === $note || mb_strlen($note) > 4000) {
            throw new DomainRuleViolation('A note has 1-4000 characters.');
        }
        $entity = new ClientNote($client, $note, $author);

        return $this->em->wrapInTransaction(function () use ($entity, $client, $actor): ClientNote {
            $this->em->persist($entity);
            $this->em->flush();
            // The note itself stays in client_notes; the audit records that it was added.
            $this->audit->record($actor, 'client.note_added', 'client', $client->getId()->toRfc4122(), ['note_id' => $entity->getId()->toRfc4122()]);

            return $entity;
        });
    }

    /**
     * Records that the client accepted a policy version. From the client dashboard only the
     * version in force can be accepted; an operator may record an acceptance made elsewhere
     * (a signed agreement) with a reference. Accepting a version twice changes nothing.
     */
    public function recordPolicyAcceptance(Client $client, string $version, PolicyAcceptanceSource $source, AuditActor $actor,
        ?User $acceptedBy = null, ?string $reference = null): ClientPolicyAcceptance
    {
        $version = trim($version);
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $version)) {
            throw new DomainRuleViolation('A policy version is a short identifier such as 2026-10.');
        }
        if (PolicyAcceptanceSource::ClientDashboard === $source && $version !== $this->policyVersionInForce()) {
            throw new DomainRuleViolation('Only the policy version in force can be accepted.');
        }
        $reference = null === $reference || '' === trim($reference) ? null : trim($reference);
        if (PolicyAcceptanceSource::OperatorRecorded === $source && null === $reference) {
            throw new DomainRuleViolation('An operator-recorded acceptance needs a reference (for example the signed agreement).');
        }
        if (null !== $reference && mb_strlen($reference) > 255) {
            throw new DomainRuleViolation('The reference has at most 255 characters.');
        }

        return $this->em->wrapInTransaction(function () use ($client, $version, $source, $actor, $acceptedBy, $reference): ClientPolicyAcceptance {
            $this->em->lock($client, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->em->getRepository(ClientPolicyAcceptance::class)->findOneBy(['client' => $client, 'policyVersion' => $version]);
            if (null !== $existing) {
                return $existing;
            }
            $acceptance = new ClientPolicyAcceptance($client, $version, $source, $acceptedBy, $reference);
            $this->em->persist($acceptance);
            $this->em->flush();
            $this->audit->record($actor, 'client.policy_accepted', 'client', $client->getId()->toRfc4122(),
                ['policy_version' => $version, 'source' => $source->value, 'reference' => $reference]);

            return $acceptance;
        });
    }

    /**
     * @return array{version_in_force: ?string, required: bool, accepted: bool, accepted_at: ?\DateTimeImmutable, blocks_approval: bool}
     */
    public function policyStatus(Client $client): array
    {
        $version = $this->policyVersionInForce();
        $acceptance = null === $version ? null
            : $this->em->getRepository(ClientPolicyAcceptance::class)->findOneBy(['client' => $client, 'policyVersion' => $version]);

        return [
            'version_in_force' => $version,
            'required' => $client->isPolicyAcceptanceRequired(),
            'accepted' => null !== $acceptance,
            'accepted_at' => $acceptance?->getAcceptedAt(),
            'blocks_approval' => null !== $version && $client->isPolicyAcceptanceRequired() && null === $acceptance,
        ];
    }

    private function assertTransition(Client $client, ClientStatus $from, ClientStatus $to, ?User $operator): void
    {
        if ($from === $to) {
            throw new DomainRuleViolation(\sprintf('The client is already %s.', $to->value));
        }
        $permission = ClientStatusTransitions::permission($from, $to)
            ?? throw new DomainRuleViolation(\sprintf('A client cannot change from %s to %s.', $from->value, $to->value));
        if (null !== $operator && !$operator->hasPermission($permission)) {
            throw new DomainRuleViolation(\sprintf('Changing a client from %s to %s needs the %s permission.', $from->value, $to->value, $permission));
        }
        if (ClientStatus::PendingApproval === $from && ClientStatus::Active === $to) {
            $this->assertPolicyAcceptedForApproval($client);
        }
    }

    private function assertPolicyAcceptedForApproval(Client $client): void
    {
        if ($this->policyStatus($client)['blocks_approval']) {
            throw new DomainRuleViolation(\sprintf('The client has not accepted policy version %s. Record the acceptance first, or exempt this client from policy acceptance.',
                $this->policyVersionInForce()));
        }
    }

    private static function auditAction(ClientStatus $from, ClientStatus $to): string
    {
        return match (true) {
            ClientStatus::Active === $to && ClientStatus::PendingApproval === $from => 'client.approved',
            ClientStatus::Active === $to && ClientStatus::Throttled === $from => 'client.unthrottled',
            ClientStatus::Suspended === $from && ClientStatus::Closed !== $to => 'client.reactivated',
            ClientStatus::Throttled === $to => 'client.throttled',
            ClientStatus::Suspended === $to => 'client.suspended',
            default => 'client.closed',
        };
    }

    /** @return array{company_name: string, contact_email: string, billing_contact_email: ?string, abuse_contact_email: ?string, plan: string} */
    private static function account(Client $client): array
    {
        return ['company_name' => $client->getCompanyName(), 'contact_email' => $client->getContactEmail(),
            'billing_contact_email' => $client->getBillingContactEmail(), 'abuse_contact_email' => $client->getAbuseContactEmail(),
            'plan' => $client->getPlan()];
    }

    /** @return array{0: string, 1: string, 2: ?string, 3: ?string, 4: string} */
    private static function accountFields(string $companyName, string $contactEmail, ?string $billing, ?string $abuse, string $plan): array
    {
        $companyName = trim($companyName);
        $plan = trim($plan);
        if ('' === $companyName || mb_strlen($companyName) > 200 || '' === $plan || mb_strlen($plan) > 100) {
            throw new DomainRuleViolation('A client needs an organisation name (at most 200 characters) and a plan (at most 100).');
        }
        $emails = [];
        foreach (['contact' => $contactEmail, 'billing' => $billing, 'abuse' => $abuse] as $label => $email) {
            $email = null === $email ? null : trim($email);
            if ('' === $email) {
                $email = null;
            }
            if (('contact' === $label && null === $email) || (null !== $email && (!filter_var($email, \FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 320))) {
                throw new DomainRuleViolation(\sprintf('The %s email is not a valid address.', $label));
            }
            $emails[] = $email;
        }

        return [$companyName, (string) $emails[0], $emails[1], $emails[2], $plan];
    }
}
