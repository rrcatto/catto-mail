<?php

declare(strict_types=1);

namespace App\Domain;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Client\ClientLimitPolicy;
use App\Domain\Dns\DnsLookupFailed;
use App\Domain\Dns\TxtResolver;
use App\Entity\Client;
use App\Entity\SendingDomain;
use App\Enum\DkimStatus;
use App\Enum\SendingDomainStatus;
use App\Sending\AddressNormalizer;
use App\Util\Clock;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sending-domain registration and DNS TXT verification (D-13, spec sending_domains).
 *
 *   register  -> `pending` with a fresh 256-bit base64url token;
 *   verify    -> looks up TXT `_smarthost-verification.<domain>`; the domain becomes
 *                `verified` (verified_at recorded) only when a record equals
 *                `smarthost-verification=<token>`; failures are recorded in
 *                last_checked_at / last_check_error and leave the status unchanged;
 *   disable / enable -> operator control; re-enabling requires verification again;
 *   setDkim   -> records the DKIM selector/status. Symfony never generates DKIM
 *                keys: OpenDKIM owns the signing keys.
 *
 * Every state change is audited. Phase 9: a client registers at most its effective
 * number of sending domains (App\Client\ClientLimitPolicy::maxSendingDomains); creations
 * serialise on the client row.
 */
final class SendingDomainService
{
    public const TXT_PREFIX = '_smarthost-verification.';
    public const TXT_VALUE_PREFIX = 'smarthost-verification=';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TxtResolver $resolver,
        private readonly AuditLogger $audit,
        private readonly int $recheckHours,
        private readonly ClientLimitPolicy $limits,
    ) {
    }

    public static function txtRecordName(SendingDomain $domain): string
    {
        return self::TXT_PREFIX.$domain->getDomain();
    }

    public static function txtRecordValue(SendingDomain $domain): string
    {
        return self::TXT_VALUE_PREFIX.$domain->getVerificationToken();
    }

    public function register(Client $client, string $domainName, AuditActor $actor): SendingDomain
    {
        $domainName = rtrim(trim($domainName), '.');
        $normalized = AddressNormalizer::normalizeDomain($domainName);
        if (null === $normalized || !AddressNormalizer::isHostname($normalized) || !str_contains($normalized, '.')) {
            throw new DomainRuleViolation(\sprintf('"%s" is not a valid domain name.', $domainName));
        }
        if (null !== $this->em->getRepository(SendingDomain::class)->findOneBy(['client' => $client, 'domain' => $normalized])) {
            throw new DomainRuleViolation(\sprintf('%s is already registered for this client.', $normalized));
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $domain = new SendingDomain($client, $normalized, $token);

        $this->assertBelowLimit($client);
        $this->em->wrapInTransaction(function () use ($domain, $client, $actor): void {
            $this->em->lock($client, LockMode::PESSIMISTIC_WRITE);
            $this->assertBelowLimit($client);
            $this->em->persist($domain);
            $this->em->flush();
            $this->audit->record($actor, 'sending_domain.created', 'sending_domain', $domain->getId()->toRfc4122(), [
                'client_id' => $domain->getClient()->getId()->toRfc4122(), 'domain' => $domain->getDomain()]);
        });

        return $domain;
    }

    private function assertBelowLimit(Client $client): void
    {
        $count = (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM sending_domains WHERE client_id = ?', [$client->getId()->toRfc4122()]);
        if ($count >= $this->limits->maxSendingDomains($client)) {
            throw new DomainRuleViolation(\sprintf('The client already has %d sending domains; its limit is %d.', $count, $this->limits->maxSendingDomains($client)));
        }
    }

    /** @return bool whether the domain is verified after the check */
    public function verify(SendingDomain $domain, AuditActor $actor): bool
    {
        if ($domain->isDisabled()) {
            throw new DomainRuleViolation('A disabled sending domain cannot be verified; enable it first.');
        }
        $now = Clock::now();
        $error = null;
        try {
            $records = array_map('trim', $this->resolver->lookup(self::txtRecordName($domain)));
            if (!\in_array(self::txtRecordValue($domain), $records, true)) {
                $error = [] === $records ? 'No TXT record found at '.self::txtRecordName($domain).'.'
                    : 'No TXT record at '.self::txtRecordName($domain).' matches the verification token.';
            }
        } catch (DnsLookupFailed $e) {
            $error = $e->getMessage();
        }
        $wasVerified = $domain->isVerified();

        $this->em->wrapInTransaction(function () use ($domain, $actor, $now, $error, $wasVerified): void {
            if (null === $error) {
                $domain->markVerified($now);
            } else {
                $domain->recordFailedCheck($now, $error);
            }
            $this->em->flush();
            if (null === $error && !$wasVerified) {
                $this->audit->record($actor, 'sending_domain.verified', 'sending_domain', $domain->getId()->toRfc4122(), [
                    'client_id' => $domain->getClient()->getId()->toRfc4122(), 'domain' => $domain->getDomain()]);
            } elseif (null !== $error) {
                $this->audit->record($actor, 'sending_domain.verification_failed', 'sending_domain', $domain->getId()->toRfc4122(), [
                    'client_id' => $domain->getClient()->getId()->toRfc4122(), 'domain' => $domain->getDomain(), 'error' => $error]);
            }
        });

        return null === $error;
    }

    /**
     * Scheduled re-check (APP_DOMAIN_VERIFICATION_RECHECK_HOURS): pending domains not
     * checked within the interval.
     *
     * @return list<SendingDomain>
     */
    public function dueForRecheck(): array
    {
        return $this->em->createQuery(
            'SELECT d FROM App\Entity\SendingDomain d WHERE d.status = :pending AND (d.lastCheckedAt IS NULL OR d.lastCheckedAt < :cutoff) ORDER BY d.createdAt ASC')
            ->setParameter('pending', SendingDomainStatus::Pending->value)
            ->setParameter('cutoff', Clock::now()->modify(\sprintf('-%d hours', max(1, $this->recheckHours))), 'timestamptz')
            ->getResult();
    }

    public function disable(SendingDomain $domain, AuditActor $actor): void
    {
        $this->change($domain, $actor, 'sending_domain.disabled', static fn () => $domain->disable());
    }

    public function enable(SendingDomain $domain, AuditActor $actor): void
    {
        if (!$domain->isDisabled()) {
            return;
        }
        $this->change($domain, $actor, 'sending_domain.enabled', static fn () => $domain->reenable());
    }

    /** Development/test bootstrap only: mark verified without DNS (refused in production). */
    public function markVerifiedWithoutDns(SendingDomain $domain, AuditActor $actor, string $smarthostEnv): void
    {
        if ('production' === $smarthostEnv) {
            throw new DomainRuleViolation('Verification without DNS is refused in production.');
        }
        $this->change($domain, $actor, 'sending_domain.verified', static fn () => $domain->markVerified(Clock::now()),
            ['method' => 'development_bootstrap_without_dns']);
    }

    public function setDkim(SendingDomain $domain, DkimStatus $status, ?string $selector, AuditActor $actor): void
    {
        $selector = null === $selector ? $domain->getDkimSelector() : strtolower(trim($selector));
        if (DkimStatus::NotConfigured !== $status && (null === $selector || 1 !== preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $selector))) {
            throw new DomainRuleViolation('A DKIM status other than not_configured needs a valid selector (a DNS label).');
        }
        if (DkimStatus::NotConfigured === $status) {
            $selector = null;
        }
        $this->change($domain, $actor, 'sending_domain.dkim_changed', static fn () => $domain->setDkim($status, $selector),
            ['dkim_status' => $status->value, 'dkim_selector' => $selector]);
    }

    /** @param array<string, mixed> $detail */
    private function change(SendingDomain $domain, AuditActor $actor, string $action, callable $mutation, array $detail = []): void
    {
        $this->em->wrapInTransaction(function () use ($domain, $actor, $action, $mutation, $detail): void {
            $mutation();
            $this->em->flush();
            $this->audit->record($actor, $action, 'sending_domain', $domain->getId()->toRfc4122(), [
                'client_id' => $domain->getClient()->getId()->toRfc4122(), 'domain' => $domain->getDomain()] + $detail);
        });
    }
}
