<?php

declare(strict_types=1);

namespace App\Domain;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\Dns\DnsLookupFailed;
use App\Domain\Dns\TxtResolver;
use App\Entity\SendingDomain;
use App\Entity\User;
use App\Enum\ClientStatus;
use App\Enum\DkimStatus;
use App\Enum\SystemRequestAction;
use App\System\SystemRequests;
use App\Util\Clock;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * DKIM for a sending domain set up in the client dashboard (owner decision 2026-10-11).
 *
 * The web application never holds or generates a key. It asks the host agent (system
 * requests, specification 2.11), which runs the production tooling an operator would run:
 *
 *   requestKey  a verified domain without a key -> dkim.generate with a new selector. The agent
 *               generates the key inside OpenDKIM (`prod dkim generate`) and returns only its
 *               public half; requestFinished() records dkim_status pending_dns, the selector and
 *               the public key, which is the record the client publishes;
 *   checkDns    pending_dns -> looks up <selector>._domainkey.<domain>; when the published key
 *               equals the recorded one, dkim.activate. The agent compares DNS with the private
 *               key again and activates the selector (`prod dkim activate`: SigningTable, OpenDKIM
 *               reloaded without a restart); requestFinished() records dkim_status active, as
 *               `smarthost:domain:dkim <client> <domain> active --selector <s>` does;
 *   advance     the host agent's periodic call (`smarthost:system:agent domains`): checkDns for
 *               every domain waiting for its record, so activation follows publication.
 *
 * When a key becomes active the account's admins are emailed (DomainReadyMail, 2026-10-27).
 *
 * Every request names its sending domain (params.sending_domain_id), so an outcome only ever
 * changes that client's domain. Requests are audited when they are made, results as the host
 * agent (sending_domain.dkim_changed).
 */
final class DkimSetup
{
    /** How often the periodic check looks again at a domain waiting for its DKIM record. */
    public const RECHECK_MINUTES = 10;
    /** Progress of a domain in the dashboard, in order. */
    public const STEPS = ['added' => 'Added', 'verified' => 'TXT verified', 'pending_dns' => 'DKIM pending in DNS', 'active' => 'Active'];

    private const AGENT = 'host-agent';
    private const PUBLIC_KEY = '#^[A-Za-z0-9+/]{100,1996}={0,2}$#';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SendingDomainService $domains,
        private readonly SystemRequests $requests,
        private readonly TxtResolver $resolver,
        private readonly AuditLogger $audit,
        private readonly string $bounceDomain,
        private readonly DomainReadyMail $readyMail,
    ) {
    }

    /** Asks the host agent for a new key; returns the request id. */
    public function requestKey(SendingDomain $domain, ?User $user): string
    {
        $this->assertClientMayChange($domain);
        if (!$domain->isVerified()) {
            throw new DomainRuleViolation(\sprintf('%s is not verified yet: publish the TXT record and check DNS first.', $domain->getDomain()));
        }
        if (DkimStatus::NotConfigured !== $domain->getDkimStatus()) {
            throw new DomainRuleViolation(\sprintf('%s already has a DKIM key (selector %s, %s).', $domain->getDomain(),
                $domain->getDkimSelector() ?? '-', str_replace('_', ' ', $domain->getDkimStatus()->value)));
        }
        $shared = $this->em->getConnection()->fetchOne(
            "SELECT 1 FROM sending_domains WHERE domain = ? AND client_id <> ? AND status = 'verified'",
            [$domain->getDomain(), $domain->getClient()->getId()->toRfc4122()]);
        if (false !== $shared) {
            // One key signs a domain for everyone: never switch another client's signing.
            throw new DomainRuleViolation(\sprintf('%s is also verified for another client of this installation; ask the operator to set up its DKIM key.', $domain->getDomain()));
        }
        $this->assertNothingWaiting($domain);
        $selector = 'cm'.Clock::now()->format('Ym').bin2hex(random_bytes(2));

        // Connection transactions throughout: a refusal must not close the entity manager.
        return $this->em->getConnection()->transactional(function () use ($domain, $user, $selector): string {
            $this->em->lock($domain, LockMode::PESSIMISTIC_WRITE);

            return $this->requests->request(SystemRequestAction::DkimGenerate, ['domain' => $domain->getDomain(), 'selector' => $selector,
                'sending_domain_id' => $domain->getId()->toRfc4122()], $user, $this->note($domain), AuditActor::system('dkim-setup'));
        });
    }

    /**
     * A domain waiting for its DKIM record: is the record published? When it is, the host agent
     * is asked to activate the selector.
     *
     * @return array{0: bool, 1: string} whether activation was requested, and what to tell the user
     */
    public function checkDns(SendingDomain $domain, ?User $user): array
    {
        $this->assertClientMayChange($domain);
        $selector = $domain->getDkimSelector();
        $key = $domain->getDkimPublicKey();
        if (!$domain->isVerified() || DkimStatus::PendingDns !== $domain->getDkimStatus() || null === $selector || null === $key) {
            throw new DomainRuleViolation(\sprintf('%s is not waiting for a DKIM record.', $domain->getDomain()));
        }
        if ($this->activationWaiting($domain)) {
            return [true, \sprintf('Signing is already being switched on for %s; it takes a minute or two.', $domain->getDomain())];
        }
        $this->assertNothingWaiting($domain);
        $name = self::dkimName($selector, $domain->getDomain());
        try {
            $published = self::publishedKey($this->resolver->lookup($name));
            $error = match (true) {
                null === $published => "No signing key record found at $name yet.",
                $published !== $key => "The record at $name does not match this domain's signing key; publish the value shown here.",
                default => null,
            };
        } catch (DnsLookupFailed $e) {
            $error = $e->getMessage();
        }
        $now = Clock::now();
        if (null !== $error) {
            $this->em->getConnection()->transactional(function () use ($domain, $user, $now, $error): void {
                $domain->recordFailedCheck($now, $error);
                $this->em->flush();
                if (null !== $user) {   // the periodic check does not fill the audit log
                    $this->audit->record(AuditActor::user($user), 'sending_domain.dkim_check_failed', 'sending_domain', $domain->getId()->toRfc4122(), [
                        'client_id' => $domain->getClient()->getId()->toRfc4122(), 'domain' => $domain->getDomain(), 'error' => $error]);
                }
            });

            return [false, $error];
        }
        $this->em->getConnection()->transactional(function () use ($domain, $user, $now, $selector): void {
            $this->em->lock($domain, LockMode::PESSIMISTIC_WRITE);
            $domain->recordPassedCheck($now);
            $this->em->flush();
            $this->requests->request(SystemRequestAction::DkimActivate, ['domain' => $domain->getDomain(), 'selector' => $selector,
                'sending_domain_id' => $domain->getId()->toRfc4122()], $user, $this->note($domain), AuditActor::system('dkim-setup'));
        });

        return [true, \sprintf('The signing key of %s is published. Signing is being switched on; it takes a minute or two.', $domain->getDomain())];
    }

    /**
     * The host agent finished a request. Only requests of the client dashboard (they name their
     * sending domain) change a domain, and only from the state the request was made in.
     *
     * @param array<string, string> $params
     * @param array<string, mixed>  $result
     */
    public function requestFinished(string $action, array $params, bool $succeeded, array $result): void
    {
        $id = $params['sending_domain_id'] ?? '';
        if (!$succeeded || !\in_array($action, ['dkim.generate', 'dkim.activate'], true) || !Uuid::isValid($id)) {
            return;
        }
        $domain = $this->em->find(SendingDomain::class, Uuid::fromString($id));
        if (null === $domain || $domain->getDomain() !== ($params['domain'] ?? '') || !$domain->isVerified()) {
            return;
        }
        $selector = (string) ($params['selector'] ?? '');
        $key = \is_string($result['public_key'] ?? null) ? (string) preg_replace('/\s+/', '', $result['public_key']) : '';
        if ('dkim.generate' === $action && DkimStatus::NotConfigured === $domain->getDkimStatus() && 1 === preg_match(self::PUBLIC_KEY, $key)) {
            $this->domains->setDkim($domain, DkimStatus::PendingDns, $selector, AuditActor::system(self::AGENT), $key);
        } elseif ('dkim.activate' === $action && DkimStatus::PendingDns === $domain->getDkimStatus() && $selector === $domain->getDkimSelector()) {
            $this->domains->setDkim($domain, DkimStatus::Active, $selector, AuditActor::system(self::AGENT));
            $this->readyMail->send($domain);
        }
    }

    /**
     * The host agent's periodic call: every verified domain waiting for its DKIM record, not
     * checked within RECHECK_MINUTES, is checked; a published record leads to activation.
     *
     * @return array{checked: int, activation_requested: int}
     */
    public function advance(): array
    {
        $ids = $this->em->getConnection()->fetchFirstColumn(<<<'SQL'
            SELECT d.id::text FROM sending_domains d JOIN clients c ON c.id = d.client_id
             WHERE d.status = 'verified' AND d.dkim_status = 'pending_dns' AND d.dkim_public_key IS NOT NULL
               AND c.status NOT IN ('suspended', 'closed')
               AND (d.last_checked_at IS NULL OR d.last_checked_at < now() - make_interval(mins => ?))
             ORDER BY d.last_checked_at NULLS FIRST LIMIT 200
            SQL, [self::RECHECK_MINUTES]);
        $checked = $requested = 0;
        foreach ($ids as $id) {
            $domain = $this->em->find(SendingDomain::class, Uuid::fromString((string) $id));
            if (null === $domain) {
                continue;
            }
            try {
                [$activating] = $this->checkDns($domain, null);
                ++$checked;
                $requested += $activating ? 1 : 0;
            } catch (DomainRuleViolation) {
            }
        }

        return ['checked' => $checked, 'activation_requested' => $requested];
    }

    /**
     * Where a domain stands, from its row and its newest DKIM request (ClientReadModel::sendingDomains):
     * added, verified, key_requested, key_failed, pending_dns, activating, active, dkim_disabled
     * or disabled.
     *
     * @param array<string, mixed> $row
     */
    public static function stage(array $row): string
    {
        $waiting = \in_array($row['request_status'] ?? null, ['pending', 'running'], true);

        return match (true) {
            'disabled' === $row['status'] => 'disabled',
            'verified' !== $row['status'] => 'added',
            'active' === $row['dkim_status'] => 'active',
            'disabled' === $row['dkim_status'] => 'dkim_disabled',
            'pending_dns' === $row['dkim_status'] => $waiting && 'dkim.activate' === ($row['request_action'] ?? null) ? 'activating' : 'pending_dns',
            $waiting && 'dkim.generate' === ($row['request_action'] ?? null) => 'key_requested',
            'dkim.generate' === ($row['request_action'] ?? null) && null !== ($row['request_status'] ?? null) => 'key_failed',
            default => 'verified',
        };
    }

    /**
     * The records the client publishes for a domain. dkim is null until the host agent generated
     * the key; spf is guidance, because the envelope sender is the bounce domain.
     *
     * @return array{txt: array{name: string, value: string}, dkim: ?array{name: string, value: string, zone: string},
     *               dmarc: array{name: string, value: string}, bounce_domain: string}
     */
    public function records(string $domain, string $token, ?string $selector, ?string $publicKey): array
    {
        $dkim = null;
        if (null !== $selector && null !== $publicKey) {
            $name = self::dkimName($selector, $domain);
            $chunks = implode(' ', array_map(static fn (string $c): string => '"'.$c.'"', str_split($publicKey, 250)));
            $dkim = ['name' => $name, 'value' => 'v=DKIM1; k=rsa; p='.$publicKey, 'zone' => "$name. IN TXT ( \"v=DKIM1; k=rsa; \" $chunks )"];
        }

        return [
            'txt' => ['name' => SendingDomainService::TXT_PREFIX.$domain, 'value' => SendingDomainService::TXT_VALUE_PREFIX.$token],
            'dkim' => $dkim,
            // A policy that only reports; no report address is made up for the client (2026-10-27).
            'dmarc' => ['name' => '_dmarc.'.$domain, 'value' => 'v=DMARC1; p=none'],
            'bounce_domain' => $this->bounceDomain,
        ];
    }

    /**
     * The domain's DMARC record when it already has one (a TXT record at _dmarc.<domain> that
     * starts with v=DMARC1), so the page can say "keep yours"; null when there is none or DNS
     * did not answer.
     */
    public function existingDmarc(string $domain): ?string
    {
        try {
            foreach ($this->resolver->lookup('_dmarc.'.$domain) as $record) {
                if (1 === preg_match('/^\s*v\s*=\s*DMARC1\s*(;|$)/i', $record)) {
                    return trim($record);
                }
            }
        } catch (DnsLookupFailed) {
        }

        return null;
    }

    public static function dkimName(string $selector, string $domain): string
    {
        return "$selector._domainkey.$domain";
    }

    /**
     * The p= value of the DKIM record among TXT records (whitespace removed), as the host
     * agent reads it (smarthost_preflight.dkim_public_key); null without a DKIM record.
     *
     * @param list<string> $records
     */
    public static function publishedKey(array $records): ?string
    {
        foreach ($records as $record) {
            $tags = [];
            foreach (explode(';', $record) as $part) {
                [$k, $v] = array_pad(explode('=', $part, 2), 2, null);
                if (null !== $v) {
                    $tags[strtolower(trim($k))] = trim($v);
                }
            }
            if (isset($tags['p']) && 'DKIM1' === strtoupper($tags['v'] ?? 'DKIM1')) {
                return (string) preg_replace('/\s+/', '', $tags['p']);
            }
        }

        return null;
    }

    /** One DKIM request per domain name waits at a time (the operator's included). */
    private function assertNothingWaiting(SendingDomain $domain): void
    {
        if (false !== $this->em->getConnection()->fetchOne(
            "SELECT 1 FROM system_requests WHERE action IN ('dkim.generate', 'dkim.activate') AND status IN ('pending', 'running') AND params_json->>'domain' = ?",
            [$domain->getDomain()])) {
            throw new DomainRuleViolation(\sprintf('A DKIM request for %s is already waiting for the host agent.', $domain->getDomain()));
        }
    }

    private function activationWaiting(SendingDomain $domain): bool
    {
        return false !== $this->em->getConnection()->fetchOne(<<<'SQL'
            SELECT 1 FROM system_requests WHERE action = 'dkim.activate' AND status IN ('pending', 'running')
               AND params_json->>'sending_domain_id' = ?
            SQL, [$domain->getId()->toRfc4122()]);
    }

    private function assertClientMayChange(SendingDomain $domain): void
    {
        if ($domain->isDisabled()) {
            throw new DomainRuleViolation(\sprintf('%s is disabled.', $domain->getDomain()));
        }
        if (\in_array($domain->getClient()->getStatus(), [ClientStatus::Suspended, ClientStatus::Closed], true)) {
            throw new DomainRuleViolation('A suspended or closed client gets no new DKIM keys.');
        }
    }

    private function note(SendingDomain $domain): string
    {
        return mb_substr('Client dashboard: '.$domain->getClient()->getCompanyName(), 0, 1000);
    }
}
