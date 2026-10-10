<?php

declare(strict_types=1);

namespace App\Security;

use App\Access\AccessControl;
use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Client\AccountAdministration;
use App\Entity\User;
use App\Util\Clock;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Uid\Uuid;

/**
 * Passwordless dashboard sign-in by single-use emailed link.
 *
 * request(): for an enabled user, or for APP_ADMIN_EMAIL even before that account
 * exists, stores the SHA-256 of a fresh 256-bit token and emails
 * <SMARTHOST_PUBLIC_BASE_URL>/dashboard/login/verify?token=<token>. The link is
 * built from the configured public URL, never from the request's Host header.
 * Requests are limited per address (5 per 15 minutes) and per client address
 * (20 per 15 minutes, stored only as a keyed hash). The caller never learns
 * whether a link was sent: unknown, disabled and rate-limited addresses look
 * exactly like a sent link.
 *
 * redeem(): one atomic UPDATE ... RETURNING claims an unused, unexpired token, so a
 * link signs in at most once even under concurrent clicks. The configured
 * administrator's account is created on first use and given ADMIN at every
 * sign-in. Disabled users are refused.
 */
final class LoginLinkService
{
    public const MAX_PER_EMAIL = 5;
    public const MAX_PER_IP = 20;
    public const WINDOW_SECONDS = 900;
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly DashboardUserProvider $users,
        private readonly AccountAdministration $accounts,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.public_base_url%')] private readonly string $publicBaseUrl,
        #[Autowire('%app.mail_from%')] private readonly string $mailFrom,
        #[Autowire('%app.login_link_ttl_seconds%')] private readonly int $ttlSeconds,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    /** @return bool whether a link was sent (for tests and logs only; never shown to the requester) */
    public function request(string $email, ?string $clientIp, ?string $returnPath = null): bool
    {
        $email = trim($email);
        if ('' === $email || mb_strlen($email) > 320 || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $user = $this->users->findByEmail($email);
        $isAdmin = $this->access->isConfiguredAdmin($email);
        if ((null === $user && !$isAdmin) || (null !== $user && $user->isDisabled())) {
            return false;
        }
        $ipHash = hash_hmac('sha256', (string) $clientIp, $this->secret);
        if ($this->rateLimited($email, $ipHash)) {
            $this->logger->warning('Sign-in link request rate-limited.', ['email_hash' => hash('sha256', strtolower($email))]);

            return false;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);
        $now = Clock::now();
        $this->connection->insert('auth_login_tokens', [
            'id' => Uuid::v7()->toRfc4122(),
            'email' => null === $user ? $email : $user->getEmail(),
            'user_id' => $user?->getId()->toRfc4122(),
            'token_hash' => $hash,
            'return_path' => self::safeReturnPath($returnPath),
            'requested_ip_hash' => $ipHash,
            'created_at' => $now->format('Y-m-d H:i:s.uP'),
            'expires_at' => $now->modify('+'.$this->ttlSeconds.' seconds')->format('Y-m-d H:i:s.uP'),
        ]);
        $url = rtrim($this->publicBaseUrl, '/').'/dashboard/login/verify?token='.$token;
        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(new Address($this->mailFrom, 'Catto Mail'))
                ->to(new Address(null === $user ? $email : $user->getEmail(), (string) $user?->getDisplayName()))
                ->subject('Your sign-in link for Catto Mail')
                ->textTemplate('email/login_link.txt.twig')
                ->htmlTemplate('email/login_link.html.twig')
                ->context(['url' => $url, 'minutes' => intdiv($this->ttlSeconds, 60)]));
        } catch (\Throwable $e) {
            $this->connection->delete('auth_login_tokens', ['token_hash' => $hash]);
            $this->logger->error('The sign-in email could not be sent.', ['exception' => $e::class]);
            throw new \RuntimeException('The sign-in email could not be sent. Please try again shortly.', 0, $e);
        }
        $this->audit->record(null === $user ? AuditActor::system('dashboard-login') : AuditActor::user($user), 'auth.login_link_requested',
            'user', $user?->getId()->toRfc4122(), ['email_hash' => hash('sha256', strtolower($email))]);

        return true;
    }

    /**
     * A single-use sign-in link for an administrator, printed on the host instead of emailed
     * (specification 2.11): the first sign-in of a new installation, before mail and DNS
     * work, and recovery when sign-in mail cannot be delivered. Only someone with a shell
     * on the host as the service user can run it (`smarthostctl prod admin-link`), and that
     * person already controls every secret of the installation; it is no wider than that.
     * The link is ADMIN-only, expires after at most 15 minutes, is shown once (only its hash
     * is stored), redeemed through the ordinary sign-in path, and audited.
     */
    public function issueHostLink(string $email, int $ttlSeconds = 900): string
    {
        $ttlSeconds = max(60, min(900, $ttlSeconds));
        $email = trim($email);
        $user = $this->users->findByEmail($email);
        $isAdmin = $this->access->isConfiguredAdmin($email)
            || (null !== $user && \in_array('ADMIN', $this->access->roleKeys($user), true));
        if (!$isAdmin || (null !== $user && $user->isDisabled())) {
            throw new \InvalidArgumentException("$email is not an enabled administrator (APP_ADMIN_EMAIL or a user with the ADMIN role).");
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = Clock::now();
        $this->connection->insert('auth_login_tokens', [
            'id' => Uuid::v7()->toRfc4122(),
            'email' => null === $user ? $email : $user->getEmail(),
            'user_id' => $user?->getId()->toRfc4122(),
            'token_hash' => hash('sha256', $token),
            'return_path' => '/dashboard/operator/setup',
            'requested_ip_hash' => hash_hmac('sha256', 'host-console', $this->secret),
            'created_at' => $now->format('Y-m-d H:i:s.uP'),
            'expires_at' => $now->modify('+'.$ttlSeconds.' seconds')->format('Y-m-d H:i:s.uP'),
        ]);
        $this->audit->record(AuditActor::system('host-console'), 'auth.host_login_link_issued', 'user', $user?->getId()->toRfc4122(),
            ['email_hash' => hash('sha256', strtolower($email)), 'expires_in_seconds' => $ttlSeconds]);

        return rtrim($this->publicBaseUrl, '/').'/dashboard/login/verify?token='.$token;
    }

    /**
     * Claims the token and returns the user to sign in and where to send them, or
     * null for an unknown, used, expired or malformed token or a disabled user.
     *
     * @return array{user: User, return_path: ?string}|null
     */
    public function redeem(string $token): ?array
    {
        if (1 !== preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }

        return $this->connection->transactional(function () use ($token): ?array {
            $row = $this->connection->fetchAssociative(<<<'SQL'
                UPDATE auth_login_tokens SET used_at = now()
                 WHERE token_hash = ? AND used_at IS NULL AND expires_at > now()
                RETURNING email, return_path
                SQL, [hash('sha256', $token)]);
            if (false === $row) {
                return null;
            }
            $user = $this->users->findByEmail((string) $row['email']);
            if (null === $user && $this->access->isConfiguredAdmin((string) $row['email'])) {
                $user = $this->accounts->createUser((string) $row['email'], null, AuditActor::system('app-admin-email'));
            }
            if (null === $user || $user->isDisabled()) {
                return null;
            }
            $this->access->bootstrapAdministrator($user);
            $this->audit->record(AuditActor::user($user), 'auth.login', 'user', $user->getId()->toRfc4122());

            return ['user' => $user, 'return_path' => self::safeReturnPath($row['return_path'] ?? null)];
        });
    }

    /** Deletes links that expired or were used more than seven days ago. */
    public function prune(): int
    {
        return (int) $this->connection->executeStatement(
            "DELETE FROM auth_login_tokens WHERE expires_at < now() - interval '7 days' OR used_at < now() - interval '7 days'");
    }

    public static function safeReturnPath(?string $path): ?string
    {
        return null !== $path && 1 === preg_match('#^/dashboard(/[A-Za-z0-9_\-/.?=&%]*)?$#', $path) && !str_contains($path, '//')
            && !str_starts_with($path, '/dashboard/login') ? $path : null;
    }

    private function rateLimited(string $email, string $ipHash): bool
    {
        $since = Clock::now()->modify('-'.self::WINDOW_SECONDS.' seconds')->format('Y-m-d H:i:s.uP');

        return (int) $this->connection->fetchOne('SELECT count(*) FROM auth_login_tokens WHERE lower(email) = lower(?) AND created_at > ?', [$email, $since]) >= self::MAX_PER_EMAIL
            || (int) $this->connection->fetchOne('SELECT count(*) FROM auth_login_tokens WHERE requested_ip_hash = ? AND created_at > ?', [$ipHash, $since]) >= self::MAX_PER_IP;
    }
}
