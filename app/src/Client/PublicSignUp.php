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
use App\Enum\ClientStatus;
use App\Enum\PolicyAcceptanceSource;
use App\Security\DashboardUserProvider;
use App\Security\LoginLinkService;
use App\Util\Clock;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Public sign-up (owner decision 2026-10-11): members of the public open an account without
 * an operator, into a starter tier with low limits. Only while APP_PUBLIC_ONBOARDING_ENABLED is
 * true.
 *
 *   request    an organisation and an email address. The verification link is a passwordless
 *              link of the existing mechanism (LoginLinkService::requestSignUp); nothing else
 *              is created. An address that already belongs to an account (a user, or a client's
 *              contact) gets no sign-up link - its owner gets an ordinary sign-in link if it is
 *              a user - and the page says the same either way (no account enumeration);
 *   complete   the confirming POST of the link's page, in one transaction: the link is used, the
 *              client is created through the gated entry point for public applications
 *              (ClientLifecycle::apply: origin public_application, pending_approval), the person
 *              becomes its client admin, the acceptance of APP_ACCEPTABLE_USE_POLICY_VERSION is
 *              recorded when one is in force (required), the client is put on the Starter plan
 *              (App\Client\PlanCatalog: its limits, never above the installation ceilings) and is
 *              activated - the transition an operator would make, audited as this service. Then
 *              the operator is emailed (APP_ADMIN_EMAIL). The operator can throttle, suspend or
 *              close the client like any other.
 */
final class PublicSignUp
{
    public const ACTOR = 'public-sign-up';
    public const PLAN = PlanCatalog::STARTER;

    public function __construct(
        private readonly ClientLifecycle $lifecycle,
        private readonly AccountAdministration $accounts,
        private readonly PlanCatalog $plans,
        private readonly LoginLinkService $links,
        private readonly DashboardUserProvider $users,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.admin_email%')] private readonly string $adminEmail,
        #[Autowire('%app.mail_from%')] private readonly string $mailFrom,
        #[Autowire('%app.public_base_url%')] private readonly string $publicBaseUrl,
        private readonly Installation $installation,
    ) {
    }

    public function enabled(): bool
    {
        return $this->lifecycle->publicOnboardingEnabled();
    }

    public function policyVersion(): ?string
    {
        return $this->lifecycle->policyVersionInForce();
    }

    /**
     * @param string|null $returnPath where the person was going (the connect page, specification 2.12);
     *                                the emailed link leads back there once the account is open
     *
     * @return 'sent'|'limited'|'taken' what happened (for tests and logs; the page never says)
     */
    public function request(string $organisation, string $email, ?string $clientIp, ?string $returnPath = null): string
    {
        $this->assertEnabled();
        [$organisation, $email] = self::valid($organisation, $email);
        if ($this->isTaken($email)) {
            // Its owner gets an ordinary sign-in link (when the address is a user that may sign in).
            $this->links->request($email, $clientIp, $returnPath);

            return 'taken';
        }

        return $this->links->requestSignUp($email, $organisation, $clientIp, $returnPath) ? 'sent' : 'limited';
    }

    /** An address that already belongs to an account: a user, or a contact of a client. */
    public function isTaken(string $email): bool
    {
        return null !== $this->users->findByEmail($email) || false !== $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM clients WHERE lower(contact_email) = lower(?)', [$email]);
    }

    /**
     * The limits a new account gets: the Starter plan's, each capped at its installation ceiling.
     *
     * @return array<string, ?int>
     */
    public function starterLimits(): array
    {
        return $this->plans->effectiveLimits(self::PLAN);
    }

    /**
     * Opens the account of a sign-up link.
     *
     * @return array{client: Client, user: User, return_path: ?string}
     */
    public function complete(string $token, bool $policyAccepted): array
    {
        $this->assertEnabled();
        // Every refusal is decided before the transaction (a refusal inside it would close the
        // entity manager), and the link is used inside it (a failure leaves it usable).
        $link = $this->links->signUpLink($token) ?? throw new DomainRuleViolation('This confirmation link is not valid any more: it was used, it expired, or it is incomplete. Sign up again to get a new one.');
        if ($this->isTaken($link['email'])) {
            throw new DomainRuleViolation('This address already belongs to an account. Sign in instead.');
        }
        $policy = $this->policyVersion();
        if (null !== $policy && !$policyAccepted) {
            throw new DomainRuleViolation(\sprintf('Accept the service policy (version %s) to open the account.', $policy));
        }
        $actor = AuditActor::system(self::ACTOR);

        $result = $this->em->getConnection()->transactional(function () use ($token, $policy, $actor): array {
            $claimed = $this->links->claimSignUpLink($token) ?? throw new DomainRuleViolation('This confirmation link was used a moment ago.');
            $user = $this->accounts->createUser($claimed['email'], null, $actor);
            $client = $this->openAccount($claimed['organisation'], $user, $policy, $actor);
            $this->audit->record(AuditActor::user($user), 'auth.login', 'user', $user->getId()->toRfc4122(), ['method' => 'public_sign_up']);

            return ['client' => $client, 'user' => $user, 'return_path' => $claimed['return_path']];
        });
        $this->notifyOperator($result['client'], $result['user']);

        return $result;
    }

    /**
     * Someone signed in without an account opens one from the connect page (specification
     * 2.13): the organisation's name is all it needs, their address is already confirmed.
     */
    public function openForUser(User $user, string $organisation, bool $policyAccepted): Client
    {
        $this->assertEnabled();
        [$organisation] = self::valid($organisation, $user->getEmail());
        $policy = $this->policyVersion();
        if (null !== $policy && !$policyAccepted) {
            throw new DomainRuleViolation(\sprintf('Accept the service policy (version %s) to open the account.', $policy));
        }
        $client = $this->em->getConnection()->transactional(fn (): Client => $this->openAccount($organisation, $user, $policy, AuditActor::system(self::ACTOR)));
        $this->notifyOperator($client, $user);

        return $client;
    }

    /** The account of a sign-up: a starter client with the person as its admin, the policy accepted, active. */
    private function openAccount(string $organisation, User $user, ?string $policy, AuditActor $actor): Client
    {
        $client = $this->lifecycle->apply($organisation, $user->getEmail(), self::PLAN);
        $this->accounts->setMembership($user, $client, ClientMembershipRole::Admin, $actor);
        if (null !== $policy) {
            $this->lifecycle->recordPolicyAcceptance($client, $policy, PolicyAcceptanceSource::ClientDashboard, AuditActor::user($user), $user);
        }
        $this->plans->assign($client, self::PLAN, $actor);
        $this->lifecycle->changeStatus($client, ClientStatus::Active, $actor,
            'public sign-up: activated automatically on the Starter plan (owner decision 2026-10-11)');

        return $client;
    }

    /** @return array{0: string, 1: string} */
    public static function valid(string $organisation, string $email): array
    {
        $organisation = trim((string) preg_replace('/\s+/u', ' ', $organisation));
        $email = trim($email);
        if (mb_strlen($organisation) < 2 || mb_strlen($organisation) > 200 || 1 === preg_match('/\p{C}/u', $organisation)) {
            throw new DomainRuleViolation('Give your organisation\'s name (2 to 200 characters).');
        }
        if ('' === $email || mb_strlen($email) > 320 || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new DomainRuleViolation('Give a valid email address: the confirmation link is sent there.');
        }

        return [$organisation, $email];
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled()) {
            throw new DomainRuleViolation('Public sign-up is not open on this installation.');
        }
    }

    /** One email per sign-up to the administrator; a failure never undoes the account. */
    private function notifyOperator(Client $client, User $user): void
    {
        if ('' === trim($this->adminEmail)) {
            return;
        }
        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(new Address($this->mailFrom, $this->installation->siteName()))
                ->to(new Address(trim($this->adminEmail)))
                ->subject('New sign-up: '.$client->getCompanyName())
                ->textTemplate('email/sign_up_operator.txt.twig')
                ->context(['client' => $client, 'client_admin' => $user->getEmail(), 'at' => Clock::now(), 'limits' => $this->starterLimits(),
                    'url' => rtrim($this->publicBaseUrl, '/').'/dashboard/operator/clients/'.$client->getId()->toRfc4122()]));
        } catch (\Throwable $e) {
            $this->logger->error('The operator could not be emailed about a new sign-up.', ['exception' => $e::class, 'client_id' => $client->getId()->toRfc4122()]);
        }
    }
}
