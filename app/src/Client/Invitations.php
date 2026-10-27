<?php

declare(strict_types=1);

namespace App\Client;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Config\Installation;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\User;
use App\Enum\ClientMembershipRole;
use App\Enum\ClientOrigin;
use App\Enum\ClientStatus;
use App\Enum\PolicyAcceptanceSource;
use App\Security\DashboardUserProvider;
use App\Security\LoginLinkService;
use App\Util\Clock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Invitations (owner decision 2026-10-27): accounts open by invitation while the public
 * sign-up stays closed. An operator makes a link on Clients › Invitations, choosing the plan
 * and whether the service policy must be accepted; whoever opens it names their organisation
 * and opens the account, which is active at once.
 *
 *   create     a single-use link of 256 random bits, valid TTL_DAYS days; only its SHA-256 is
 *              stored, so the link is shown once (audited as invitation.created);
 *   request    a visitor gives the organisation and their address: a confirmation link of the
 *              ordinary sign-up kind, naming the invitation, is emailed (nothing else is created).
 *              An address that already has an account gets an ordinary sign-in link instead, and
 *              the page says the same either way;
 *   complete   the confirmation link's POST, in one transaction: the link and the invitation are
 *              used, the client is created (origin invitation) on the invitation's plan, the
 *              person becomes its client admin, the acceptance of the policy version in force is
 *              recorded when the invitation requires it, and the client is activated;
 *   openForUser  someone already signed in opens the account with the organisation's name alone;
 *   revoke     an unused invitation stops working (audited).
 *
 * The operator (APP_ADMIN_EMAIL) is emailed when an invitation opens an account.
 */
final class Invitations
{
    public const TTL_DAYS = 7;
    public const ACTOR = 'invitation';
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly ClientLifecycle $lifecycle,
        private readonly AccountAdministration $accounts,
        private readonly PlanCatalog $plans,
        private readonly LoginLinkService $links,
        private readonly DashboardUserProvider $users,
        private readonly AuditLogger $audit,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly Installation $installation,
        #[Autowire('%app.admin_email%')] private readonly string $adminEmail,
        #[Autowire('%app.mail_from%')] private readonly string $mailFrom,
        #[Autowire('%app.public_base_url%')] private readonly string $publicBaseUrl,
    ) {
    }

    /**
     * @return array{id: string, url: string} the link, which exists only in this answer
     */
    public function create(string $plan, bool $policyRequired, string $note, User $operator): array
    {
        if (null === $this->plans->find($plan)) {
            throw new DomainRuleViolation('Choose one of the plans.');
        }
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));
        if (mb_strlen($note) > 200 || 1 === preg_match('/\p{C}/u', $note)) {
            throw new DomainRuleViolation('Who it is for: at most 200 characters.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = Uuid::v7()->toRfc4122();
        $now = Clock::now();
        $this->connection->transactional(function () use ($id, $token, $plan, $policyRequired, $note, $operator, $now): void {
            $this->connection->insert('client_invitations', ['id' => $id, 'token_hash' => hash('sha256', $token), 'plan' => $plan,
                'policy_acceptance_required' => $policyRequired, 'note' => '' === $note ? null : $note,
                'created_by_user_id' => $operator->getId()->toRfc4122(), 'created_at' => $now->format('Y-m-d H:i:s.uP'),
                'expires_at' => $now->modify('+'.self::TTL_DAYS.' days')->format('Y-m-d H:i:s.uP')], ['policy_acceptance_required' => 'boolean']);
            $this->audit->record(AuditActor::user($operator), 'invitation.created', 'client_invitation', $id,
                ['plan' => $plan, 'policy_acceptance_required' => $policyRequired, 'note' => '' === $note ? null : $note]);
        });

        return ['id' => $id, 'url' => self::link($this->publicBaseUrl, $token)];
    }

    public static function link(string $publicBaseUrl, string $token): string
    {
        return rtrim($publicBaseUrl, '/').'/dashboard/invitation?token='.$token;
    }

    /**
     * Every invitation, newest first, with its state: open, used, expired or revoked.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(static function (array $r): array {
            $r['state'] = match (true) {
                null !== $r['used_at'] => 'used',
                null !== $r['revoked_at'] => 'revoked',
                filter_var($r['expired'], \FILTER_VALIDATE_BOOLEAN) => 'expired',
                default => 'open',
            };

            return $r;
        }, $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT i.id::text AS id, i.plan, p.name AS plan_name, i.policy_acceptance_required, i.note, i.created_at, i.expires_at,
                   i.used_at, i.revoked_at, i.client_id::text AS client_id, c.company_name, u.email AS created_by, i.expires_at <= now() AS expired
              FROM client_invitations i JOIN plans p ON p.id = i.plan JOIN users u ON u.id = i.created_by_user_id
              LEFT JOIN clients c ON c.id = i.client_id
             ORDER BY i.created_at DESC, i.id DESC LIMIT 200
            SQL));
    }

    /** How many invitations can still be used (the Clients menu's view). */
    public function openCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM client_invitations WHERE used_at IS NULL AND revoked_at IS NULL AND expires_at > now()');
    }

    public function revoke(string $id, User $operator): void
    {
        if (!Uuid::isValid($id)) {
            throw new DomainRuleViolation('That invitation does not exist.');
        }
        $this->connection->transactional(function () use ($id, $operator): void {
            $done = $this->connection->executeStatement(
                'UPDATE client_invitations SET revoked_at = ? WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL',
                [Clock::now()->format('Y-m-d H:i:s.uP'), $id]);
            if (1 !== $done) {
                throw new DomainRuleViolation('That invitation was already used or withdrawn.');
            }
            $this->audit->record(AuditActor::user($operator), 'invitation.revoked', 'client_invitation', $id);
        });
    }

    /**
     * The invitation a link names, while it can be used.
     *
     * @return array{id: string, plan: string, plan_name: string, policy_required: bool, expires_at: string}|null
     */
    public function find(string $token): ?array
    {
        if (1 !== preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }

        return $this->usable('i.token_hash = ?', hash('sha256', $token));
    }

    /**
     * @return 'sent'|'limited'|'taken' what happened (for tests and logs; the page never says)
     */
    public function request(string $token, string $organisation, string $email, ?string $clientIp): string
    {
        $invitation = $this->find($token) ?? throw new DomainRuleViolation(self::gone());
        [$organisation, $email] = PublicSignUp::valid($organisation, $email);
        if (null !== $this->users->findByEmail($email)) {
            $this->links->request($email, $clientIp);

            return 'taken';
        }

        return $this->links->requestSignUp($email, $organisation, $clientIp, null, $invitation['id']) ? 'sent' : 'limited';
    }

    /**
     * The confirmation link's page: the link and its invitation, while both can be used.
     *
     * @return array{link: array{email: string, organisation: string, return_path: ?string, invitation_id: ?string}, invitation: array{id: string, plan: string, plan_name: string, policy_required: bool, expires_at: string}}|null
     */
    public function confirmation(string $linkToken): ?array
    {
        $link = $this->links->signUpLink($linkToken);
        $invitation = null === $link || null === $link['invitation_id'] ? null : $this->usable('i.id = ?', $link['invitation_id']);

        return null === $invitation ? null : ['link' => $link, 'invitation' => $invitation];
    }

    public function policyVersion(): ?string
    {
        return $this->lifecycle->policyVersionInForce();
    }

    /**
     * Opens the account of a confirmation link.
     *
     * @return array{client: Client, user: User}
     */
    public function complete(string $linkToken, bool $policyAccepted): array
    {
        $found = $this->confirmation($linkToken) ?? throw new DomainRuleViolation(self::gone());
        if (null !== $this->users->findByEmail($found['link']['email'])) {
            throw new DomainRuleViolation('This address already has an account. Sign in, then open the invitation link again.');
        }
        $this->assertPolicy($found['invitation'], $policyAccepted);

        return $this->em->getConnection()->transactional(function () use ($linkToken, $found): array {
            $claimed = $this->links->claimSignUpLink($linkToken);
            if (null === $claimed || $claimed['invitation_id'] !== $found['invitation']['id']) {
                throw new DomainRuleViolation('This confirmation link was used a moment ago.');
            }
            $user = $this->accounts->createUser($claimed['email'], null, AuditActor::system(self::ACTOR));
            $client = $this->open($found['invitation'], $claimed['organisation'], $user);
            $this->audit->record(AuditActor::user($user), 'auth.login', 'user', $user->getId()->toRfc4122(), ['method' => 'invitation']);

            return ['client' => $client, 'user' => $user];
        });
    }

    /** Someone signed in opens the account of an invitation with the organisation's name. */
    public function openForUser(string $token, string $organisation, bool $policyAccepted, User $user): Client
    {
        $invitation = $this->find($token) ?? throw new DomainRuleViolation(self::gone());
        [$organisation] = PublicSignUp::valid($organisation, $user->getEmail());
        $this->assertPolicy($invitation, $policyAccepted);

        return $this->em->getConnection()->transactional(fn (): Client => $this->open($invitation, $organisation, $user));
    }

    /** @param array{id: string, plan: string, plan_name: string, policy_required: bool, expires_at: string} $invitation */
    private function open(array $invitation, string $organisation, User $user): Client
    {
        $actor = AuditActor::system(self::ACTOR);
        $client = $this->lifecycle->create($organisation, $user->getEmail(), $invitation['plan'], ClientStatus::PendingApproval, $actor,
            $invitation['policy_required'], ClientOrigin::Invitation);
        $used = $this->connection->executeStatement(
            'UPDATE client_invitations SET used_at = ?, client_id = ? WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL AND expires_at > now()',
            [Clock::now()->format('Y-m-d H:i:s.uP'), $client->getId()->toRfc4122(), $invitation['id']]);
        if (1 !== $used) {
            throw new DomainRuleViolation(self::gone());
        }
        $this->accounts->setMembership($user, $client, ClientMembershipRole::Admin, $actor);
        $policy = $this->lifecycle->policyVersionInForce();
        if (null !== $policy && $invitation['policy_required']) {
            $this->lifecycle->recordPolicyAcceptance($client, $policy, PolicyAcceptanceSource::ClientDashboard, AuditActor::user($user), $user);
        }
        $this->plans->assign($client, $invitation['plan'], $actor);
        $this->lifecycle->changeStatus($client, ClientStatus::Active, $actor, 'opened with an operator\'s invitation');
        $this->audit->record($actor, 'invitation.used', 'client_invitation', $invitation['id'], ['client_id' => $client->getId()->toRfc4122()]);
        $this->notifyOperator($client, $user);

        return $client;
    }

    /** @param array{policy_required: bool} $invitation */
    private function assertPolicy(array $invitation, bool $accepted): void
    {
        $policy = $this->lifecycle->policyVersionInForce();
        if ($invitation['policy_required'] && null !== $policy && !$accepted) {
            throw new DomainRuleViolation(\sprintf('Accept the service policy (version %s) to open the account.', $policy));
        }
    }

    /** @return array{id: string, plan: string, plan_name: string, policy_required: bool, expires_at: string}|null */
    private function usable(string $where, string $value): ?array
    {
        $row = $this->connection->fetchAssociative(<<<SQL
            SELECT i.id::text AS id, i.plan, p.name AS plan_name, i.policy_acceptance_required, i.expires_at
              FROM client_invitations i JOIN plans p ON p.id = i.plan
             WHERE $where AND i.used_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > now()
            SQL, [$value]);

        return false === $row ? null : ['id' => (string) $row['id'], 'plan' => (string) $row['plan'], 'plan_name' => (string) $row['plan_name'],
            'policy_required' => filter_var($row['policy_acceptance_required'], \FILTER_VALIDATE_BOOLEAN), 'expires_at' => (string) $row['expires_at']];
    }

    private static function gone(): string
    {
        return 'This invitation cannot be used any more: it was used, withdrawn or has expired. Ask the person who sent it for a new one.';
    }

    /** One email to the administrator; a failure never undoes the account. */
    private function notifyOperator(Client $client, User $user): void
    {
        if ('' === trim($this->adminEmail)) {
            return;
        }
        $url = rtrim($this->publicBaseUrl, '/').'/dashboard/operator/clients/'.$client->getId()->toRfc4122();
        try {
            $this->mailer->send((new Email())
                ->from(new Address($this->mailFrom, $this->installation->siteName()))
                ->to(new Address(trim($this->adminEmail)))
                ->subject('Invitation used: '.$client->getCompanyName())
                ->text(\sprintf("%s opened an account for %s with one of your invitations, on the %s plan.\n\n%s\n",
                    $user->getEmail(), $client->getCompanyName(), $this->plans->name($client->getPlan()), $url)));
        } catch (\Throwable $e) {
            $this->logger->error('The operator could not be emailed about a used invitation.', ['exception' => $e::class, 'client_id' => $client->getId()->toRfc4122()]);
        }
    }
}
