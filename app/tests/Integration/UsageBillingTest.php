<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditActor;
use App\Domain\DomainRuleViolation;
use App\Entity\BillingStatement;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;
use App\Tests\Support\Phase9Fixtures;
use App\Usage\BillingStatementService;
use App\Usage\UsageExporter;
use App\Usage\UsagePeriod;
use App\Usage\UsageReconciliation;
use App\Usage\UsageReporting;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Phase 9 usage metering and the billing boundary (specification 2.10
 * usage_and_billing): period summaries by unit, reconciliation of metered usage
 * against validation jobs and messages (consistent, then each kind of inconsistency),
 * the one-unit-per-message guarantee, provider-neutral statements (prepare, finalize,
 * mark exported, void) and the deterministic, audited export.
 */
final class UsageBillingTest extends ApiTestCase
{
    /** The installation's time zone (APP_TIMEZONE): usage periods are its calendar days and months. */
    private function zone(): \DateTimeZone
    {
        return $this->container()->get(\App\Util\InstallationTime::class)->zone;
    }

    private function previousMonth(string $offset = '+3 days'): string
    {
        return (new \DateTimeImmutable('first day of last month 10:00', $this->zone()))->modify($offset)->format('Y-m-d H:i:sP');
    }

    /** @return array{0: \App\Entity\Client, 1: string, 2: array{job: string, messages: list<string>}, 3: string} */
    private function meteredClient(): array
    {
        $client = $this->newClient();
        $id = $client->getId()->toRfc4122();
        $domain = $this->verifiedDomain($client);
        $o = Db::owner();
        $validation = Phase9Fixtures::meteredValidationJob($o, $id, 30, $this->previousMonth());
        $send = Phase9Fixtures::meteredSendJob($o, $id, $domain->getId()->toRfc4122(), 12, $this->previousMonth('+5 days'));
        // Current-month usage that must not appear in the previous month.
        Phase9Fixtures::meteredValidationJob($o, $id, 7, gmdate('Y-m-d H:i:s'));

        return [$client, $id, $send, $validation];
    }

    public function testPeriodSummariesKeepUnitsApart(): void
    {
        [, $id] = $this->meteredClient();
        /** @var UsageReporting $reporting */
        $reporting = $this->service(UsageReporting::class);
        $previous = $reporting->clientTotals($id, UsagePeriod::named('previous_month', $this->zone()));
        self::assertSame(['validation_address' => ['quantity' => 30, 'records' => 1], 'message_submitted' => ['quantity' => 12, 'records' => 12]], $previous);
        self::assertSame(7, $reporting->clientTotals($id, UsagePeriod::named('current_month', $this->zone()))['validation_address']['quantity']);
        self::assertSame(7, $reporting->clientTotals($id, UsagePeriod::named('current_day', $this->zone()))['validation_address']['quantity']);
        self::assertSame(0, $reporting->clientTotals($id, UsagePeriod::named('current_day', $this->zone()))['message_submitted']['quantity']);
        $daily = $reporting->daily($id, UsagePeriod::named('previous_month', $this->zone()));
        self::assertSame([30, 12], [array_sum(array_column($daily, 'validation_address')), array_sum(array_column($daily, 'message_submitted'))]);
        foreach ([['2026-01-01', '2027-01-03'], ['2026-02-01', '2026-01-01'], ['2026-13-01', '2026-12-01']] as [$from, $to]) {
            try {
                UsagePeriod::custom($from, $to, $this->zone());
                self::fail("$from..$to");
            } catch (DomainRuleViolation) {
            }
        }
    }

    public function testReconciliationFindsEveryInconsistency(): void
    {
        [$client, $id, $send, $validation] = $this->meteredClient();
        /** @var UsageReconciliation $reconciliation */
        $reconciliation = $this->service(UsageReconciliation::class);
        $period = UsagePeriod::named('previous_month', $this->zone());
        $r = $reconciliation->reconcile($id, $period);
        self::assertSame('consistent', $r['status'], json_encode($r['findings']));
        self::assertSame(['validation_jobs' => 1, 'message_usage_records' => 12, 'messages_accepted' => 12], $r['checked']);

        $o = Db::owner();
        $at = $this->previousMonth('+6 days');
        // Over-metered validation job, a message accepted but not metered, a unit for another client's message.
        $o->insert('usage_records', ['id' => \App\Tests\Schema\SchemaFixtures::id(), 'client_id' => $id, 'usage_type' => 'validation_address',
            'quantity' => 2, 'reference_type' => 'validation_job', 'reference_id' => $validation, 'occurred_at' => $at]);
        $o->executeStatement("DELETE FROM usage_records WHERE reference_id = ? AND usage_type = 'message_submitted'", [$send['messages'][0]]);
        $other = $this->newClient();
        $foreign = Phase9Fixtures::meteredSendJob($o, $other->getId()->toRfc4122(), $this->verifiedDomain($other)->getId()->toRfc4122(), 1, $at);
        $o->executeStatement("UPDATE usage_records SET client_id = ? WHERE reference_id = ? AND usage_type = 'message_submitted'", [$id, $foreign['messages'][0]]);

        $r = $reconciliation->reconcile($id, $period);
        self::assertSame('inconsistent', $r['status']);
        $types = array_count_values(array_column($r['findings'], 'type'));
        ksort($types);
        self::assertSame(['message_usage_invalid' => 1, 'message_without_usage' => 1, 'validation_usage_mismatch' => 1], $types);
        $mismatch = array_values(array_filter($r['findings'], static fn (array $f): bool => 'validation_usage_mismatch' === $f['type']))[0];
        self::assertSame(['metered' => 32, 'done_addresses' => 30], ['metered' => $mismatch['metered'], 'done_addresses' => $mismatch['done_addresses']]);

        // A message can never be metered twice (usage_records_message_once_uq).
        try {
            $o->insert('usage_records', ['id' => \App\Tests\Schema\SchemaFixtures::id(), 'client_id' => $id, 'usage_type' => 'message_submitted',
                'quantity' => 1, 'reference_type' => 'message', 'reference_id' => $send['messages'][1], 'occurred_at' => $at]);
            self::fail('duplicate message unit');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
        }

        // The console reports and exits 1.
        static::bootKernel();
        $t = new CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel))->find('smarthost:usage:reconcile'));
        self::assertSame(1, $t->execute(['--client' => $id, '--period' => 'previous_month']));
        self::assertStringContainsString('message_without_usage', $t->getDisplay());
    }

    public function testStatementsExportAndAudit(): void
    {
        [$client, $id] = $this->meteredClient();
        /** @var BillingStatementService $statements */
        $statements = $this->service(BillingStatementService::class);
        $period = UsagePeriod::named('previous_month', $this->zone());
        $actor = AuditActor::system('test');
        $s = $statements->prepare($this->reload($client), $period, $actor);
        self::assertSame(['draft', 'consistent'], [$s->getStatus()->value, $s->getReconciliationStatus()->value]);
        $lines = Db::owner()->fetchAllKeyValue('SELECT usage_type, quantity FROM billing_statement_lines WHERE statement_id = ? ORDER BY 1', [$s->getId()->toRfc4122()]);
        self::assertSame(['message_submitted' => 12, 'validation_address' => 30], array_map('intval', $lines));
        try {
            $statements->prepare($this->reload($client), UsagePeriod::named('current_month', $this->zone()), $actor);
            self::fail('only ended periods');
        } catch (DomainRuleViolation) {
        }

        // Usage that arrives after preparation blocks finalization until the draft is prepared again.
        Phase9Fixtures::meteredValidationJob(Db::owner(), $id, 4, $this->previousMonth('+8 days'));
        $statement = $this->reload($s);
        try {
            $statements->finalize($statement, $actor);
            self::fail('stale totals');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('validation_address', $e->getMessage());
        }
        $statement = $statements->prepare($this->reload($client), $period, $actor);
        self::assertSame($s->getId()->toRfc4122(), $statement->getId()->toRfc4122(), 're-preparing a draft keeps the statement');
        $statements->finalize($statement, $actor);
        try {
            $statements->prepare($this->reload($client), $period, $actor);
            self::fail('a finalized period is not prepared again');
        } catch (DomainRuleViolation) {
        }
        $statements->markExported($this->reload($statement), 'INV-2026-0042', $actor);
        $row = Db::owner()->fetchAssociative('SELECT status, external_reference, finalized_at IS NOT NULL AS f, exported_at IS NOT NULL AS e FROM billing_statements WHERE id = ?', [$s->getId()->toRfc4122()]);
        self::assertSame(['status' => 'exported', 'external_reference' => 'INV-2026-0042', 'f' => true, 'e' => true], $row);
        try {
            $statements->void($this->reload($statement), 'too late', $actor);
            self::fail('an exported statement is final');
        } catch (DomainRuleViolation) {
        }

        /** @var UsageExporter $exporter */
        $exporter = $this->service(UsageExporter::class);
        $export = $exporter->export($this->reload($client), $period, $actor);
        self::assertSame([['usage_type' => 'validation_address', 'quantity' => 34, 'usage_records' => 2, 'source' => 'usage_records'],
            ['usage_type' => 'message_submitted', 'quantity' => 12, 'usage_records' => 12, 'source' => 'usage_records']], $export['categories']);
        self::assertSame(['consistent', 'exported'], [$export['reconciliation']['status'], $export['billing_statement']['status']]);
        $again = $exporter->export($this->reload($client), $period, $actor);
        unset($export['generated_at'], $again['generated_at']);
        self::assertSame($export, $again, 'deterministic');
        $csv = UsageExporter::csv($export);
        self::assertStringContainsString("$id,{$export['period']['start']},{$export['period']['end']},message_submitted,12,12,usage_records,consistent,exported", $csv);
        self::assertSame(2, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'usage.exported' AND target_id = ?", [$id]));
        self::assertSame(['billing_statement.prepared', 'billing_statement.prepared', 'billing_statement.finalized', 'billing_statement.exported'],
            Db::owner()->fetchFirstColumn("SELECT action FROM audit_log WHERE target_type = 'billing_statement' AND target_id = ? ORDER BY occurred_at", [$s->getId()->toRfc4122()]));
    }

    public function testVoidAndPrepareAgain(): void
    {
        [$client] = $this->meteredClient();
        /** @var BillingStatementService $statements */
        $statements = $this->service(BillingStatementService::class);
        $period = UsagePeriod::named('previous_month', $this->zone());
        $first = $statements->prepare($this->reload($client), $period, self::actor());
        $statements->finalize($this->reload($first), self::actor());
        $statements->void($this->reload($first), 'wrong plan applied downstream', self::actor());
        $second = $statements->prepare($this->reload($client), $period, self::actor());
        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertSame(['void', 'draft'], Db::owner()->fetchFirstColumn('SELECT status FROM billing_statements WHERE client_id = ? ORDER BY created_at', [$client->getId()->toRfc4122()]));
        self::assertInstanceOf(BillingStatement::class, $statements->current($this->reload($client), $period));
    }
}
