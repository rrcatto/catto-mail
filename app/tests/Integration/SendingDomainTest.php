<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\DomainRuleViolation;
use App\Domain\SendingDomainService;
use App\Enum\DkimStatus;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;
use App\Tests\Support\StubTxtResolver;

/** Sending-domain registration and DNS TXT verification (D-13) with stubbed DNS. */
final class SendingDomainTest extends ApiTestCase
{
    private function domains(): SendingDomainService
    {
        return $this->container()->get(SendingDomainService::class);
    }

    private function dns(): StubTxtResolver
    {
        return $this->container()->get(\App\Domain\Dns\TxtResolver::class);
    }

    public function testRegistrationCreatesAPendingDomainWithARandomToken(): void
    {
        $client = $this->newClient();
        $d = $this->domains()->register($client, ' Mail.Example.ORG. ', self::actor());
        self::assertSame('mail.example.org', $d->getDomain());
        self::assertSame('pending', $d->getStatus()->value);
        self::assertSame('not_configured', $d->getDkimStatus()->value);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $d->getVerificationToken(), '256-bit base64url token');
        self::assertSame('_smarthost-verification.mail.example.org', SendingDomainService::txtRecordName($d));
        self::assertSame('smarthost-verification='.$d->getVerificationToken(), SendingDomainService::txtRecordValue($d));
        self::assertNotSame($d->getVerificationToken(), $this->domains()->register($client, 'other.example.org', self::actor())->getVerificationToken());
        self::assertSame('sending_domain.created', Db::owner()->fetchOne("SELECT action FROM audit_log WHERE target_id = ?", [$d->getId()->toRfc4122()]));
    }

    public function testInvalidAndDuplicateDomainsAreRejected(): void
    {
        $client = $this->newClient();
        foreach (['localhost', 'bad_domain.example', '-x.example', 'user@example.com', '', 'a..b.example'] as $bad) {
            try {
                $this->domains()->register($this->reload($client), $bad, self::actor());
                self::fail("accepted $bad");
            } catch (DomainRuleViolation) {
                $this->container()->get('doctrine')->resetManager();
            }
        }
        $this->domains()->register($this->reload($client), 'dup.example', self::actor());
        $this->expectException(DomainRuleViolation::class);
        $this->domains()->register($this->reload($client), 'DUP.example', self::actor());
    }

    public function testInternationalisedDomainsAreStoredAsALabels(): void
    {
        $d = $this->domains()->register($this->newClient(), 'Bücher.example', self::actor());
        self::assertSame('xn--bcher-kva.example', $d->getDomain());
    }

    public function testSuccessfulVerification(): void
    {
        $d = $this->domains()->register($this->newClient(), 'ok.example', self::actor());
        $this->dns()->set('_smarthost-verification.ok.example', ['v=spf1 -all', '  '.SendingDomainService::txtRecordValue($d).' ']);
        self::assertTrue($this->domains()->verify($d, self::actor()));
        $row = Db::owner()->fetchAssociative('SELECT status, verified_at, last_checked_at, last_check_error FROM sending_domains WHERE id = ?', [$d->getId()->toRfc4122()]);
        self::assertSame('verified', $row['status']);
        self::assertNotNull($row['verified_at']);
        self::assertNotNull($row['last_checked_at']);
        self::assertNull($row['last_check_error']);
        self::assertContains('sending_domain.verified', Db::owner()->fetchFirstColumn('SELECT action FROM audit_log WHERE target_id = ?', [$d->getId()->toRfc4122()]));
        // Re-verification keeps the original verified_at.
        $first = $row['verified_at'];
        self::assertTrue($this->domains()->verify($d, self::actor()));
        self::assertSame($first, Db::owner()->fetchOne('SELECT verified_at FROM sending_domains WHERE id = ?', [$d->getId()->toRfc4122()]));
    }

    public function testFailedVerificationLeavesTheDomainPending(): void
    {
        $client = $this->newClient();
        $missing = $this->domains()->register($client, 'none.example', self::actor());
        self::assertFalse($this->domains()->verify($missing, self::actor()));
        self::assertSame('pending', $missing->getStatus()->value);
        self::assertStringContainsString('No TXT record found', (string) $missing->getLastCheckError());

        $wrong = $this->domains()->register($this->reload($client), 'wrong.example', self::actor());
        $this->dns()->set('_smarthost-verification.wrong.example', ['smarthost-verification=not-the-token']);
        self::assertFalse($this->domains()->verify($wrong, self::actor()));
        self::assertStringContainsString('matches', (string) $wrong->getLastCheckError());

        $prefix = $this->domains()->register($this->reload($client), 'prefix.example', self::actor());
        $this->dns()->set('_smarthost-verification.prefix.example', [SendingDomainService::txtRecordValue($prefix).'x']);
        self::assertFalse($this->domains()->verify($prefix, self::actor()), 'exact match only');

        $servfail = $this->domains()->register($this->reload($client), 'servfail.example', self::actor());
        $this->dns()->fail('_smarthost-verification.servfail.example');
        self::assertFalse($this->domains()->verify($servfail, self::actor()));
        self::assertStringContainsString('failed', (string) $servfail->getLastCheckError());
        self::assertSame('pending', Db::owner()->fetchOne('SELECT status FROM sending_domains WHERE id = ?', [$servfail->getId()->toRfc4122()]));
        self::assertContains('sending_domain.verification_failed', Db::owner()->fetchFirstColumn('SELECT action FROM audit_log WHERE target_id = ?', [$servfail->getId()->toRfc4122()]));
    }

    public function testStateTransitions(): void
    {
        $d = $this->domains()->register($this->newClient(), 'states.example', self::actor());
        $this->dns()->set('_smarthost-verification.states.example', [SendingDomainService::txtRecordValue($d)]);
        $this->domains()->verify($d, self::actor());
        self::assertTrue($d->isVerified());
        $this->domains()->disable($d, self::actor());
        self::assertTrue($d->isDisabled());
        try {
            $this->domains()->verify($d, self::actor());
            self::fail('a disabled domain cannot be verified');
        } catch (DomainRuleViolation) {
        }
        $this->domains()->enable($d, self::actor());
        self::assertSame('pending', $d->getStatus()->value, 're-enabling requires a new verification');
        self::assertNull($d->getVerifiedAt());
        self::assertTrue($this->domains()->verify($d, self::actor()));
    }

    public function testDkimSelectorAndStatus(): void
    {
        $d = $this->domains()->register($this->newClient(), 'dkim.example', self::actor());
        $this->domains()->setDkim($d, DkimStatus::PendingDns, 'S2026', self::actor());
        self::assertSame(['pending_dns', 's2026'], [$d->getDkimStatus()->value, $d->getDkimSelector()]);
        $this->domains()->setDkim($d, DkimStatus::Active, null, self::actor());
        self::assertSame(['active', 's2026'], [$d->getDkimStatus()->value, $d->getDkimSelector()]);
        $this->domains()->setDkim($d, DkimStatus::NotConfigured, null, self::actor());
        self::assertNull($d->getDkimSelector());
        $this->expectException(DomainRuleViolation::class);
        $this->domains()->setDkim($d, DkimStatus::Active, 'bad selector', self::actor());
    }

    public function testRecheckSelectsPendingDomainsDueForACheck(): void
    {
        $client = $this->newClient();
        $due = $this->domains()->register($client, 'due-'.bin2hex(random_bytes(3)).'.example', self::actor());
        $recent = $this->domains()->register($this->reload($client), 'recent-'.bin2hex(random_bytes(3)).'.example', self::actor());
        $this->domains()->verify($recent, self::actor()); // fails, but sets last_checked_at = now
        $ids = array_map(fn ($d) => $d->getId()->toRfc4122(), $this->domains()->dueForRecheck());
        self::assertContains($due->getId()->toRfc4122(), $ids);
        self::assertNotContains($recent->getId()->toRfc4122(), $ids);
    }

    public function testTheSameDomainMayBeRegisteredByTwoClients(): void
    {
        $a = $this->domains()->register($this->newClient(), 'shared.example', self::actor());
        $b = $this->domains()->register($this->newClient(), 'shared.example', self::actor());
        self::assertNotSame($a->getVerificationToken(), $b->getVerificationToken());
        $this->dns()->set('_smarthost-verification.shared.example', [SendingDomainService::txtRecordValue($b)]);
        self::assertFalse($this->domains()->verify($a, self::actor()), "B's record does not verify A");
        self::assertTrue($this->domains()->verify($b, self::actor()));
    }
}
