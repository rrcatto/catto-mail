<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\AddressBatch\AddressBatchService;
use App\AddressBatch\BatchFileParser;
use App\AddressBatch\BatchReadModel;
use App\AddressBatch\BatchSender;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\User;
use App\Enum\AddressBatchPurpose;
use App\Enum\BatchReviewDecision;
use App\Tests\Schema\SchemaFixtures;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;

/**
 * Administrator address batches (specification 2.11): parsing (TXT, CSV, cleanup,
 * duplicates, malformed rows, the 10,000 limit), import provenance, validation through
 * ordinary validation jobs, the separate state dimensions, suppression filtering, typo
 * decisions, review decisions, CSV reports, and staged sends that never select an
 * ineligible address and never go out while delivery is held.
 */
final class AddressBatchTest extends DashboardTestCase
{
    public function testParserKeepsEveryRowAndExplainsIt(): void
    {
        $p = BatchFileParser::parse("Alice@Example.COM\n\n  bob@example.org \nAlice@example.com\nnot-an-address\nCarol <carol@Example.net>\nmailto:dan@example.org\nbad user@example.org\n", 'list.txt');
        self::assertSame('txt', $p['format']);
        self::assertSame(['data_rows' => 7, 'imported' => 4, 'duplicate' => 1, 'malformed' => 2, 'blank' => 1], $p['counts']);
        $by = array_column($p['rows'], null, 'row');
        self::assertSame('Alice@example.com', $by[1]['normalized'], 'the local part is preserved, the domain lower-cased');
        self::assertSame(['duplicate', 1], [$by[4]['outcome'], $by[4]['duplicate_of_row']]);
        self::assertSame('malformed', $by[5]['outcome']);
        self::assertSame('carol@example.net', $by[6]['normalized']);
        self::assertSame('dan@example.org', $by[7]['normalized']);
        self::assertStringContainsString('spaces', (string) $by[8]['detail']);
        self::assertSame($p['counts']['data_rows'], $p['counts']['imported'] + $p['counts']['duplicate'] + $p['counts']['malformed'], 'no row is lost');

        $csv = BatchFileParser::parse("name;E-Mail;city\nAnn;ann@example.org;Rome\nBen;;Oslo\n\"Cy, Jr\";cy@example.org;Paris\n", 'x.csv');
        self::assertSame(['csv', ';', 'E-Mail'], [$csv['format'], $csv['delimiter'], $csv['column']]);
        self::assertSame(2, $csv['counts']['imported']);
        self::assertSame('city', BatchFileParser::parse("name,mail2,city\nA,a@x.example,b@y.example\n", 'y.csv', 'city')['column']);
        try {
            BatchFileParser::parse("a,b\n1,2\n", 'z.csv', 'missing');
            self::fail('unknown column');
        } catch (DomainRuleViolation) {
        }
    }

    public function testLimitsAreEnforced(): void
    {
        $rows = implode("\n", array_map(static fn (int $i): string => "u$i@example.org", range(1, 10001)));
        try {
            BatchFileParser::parse($rows, 'big.txt');
            self::fail('more than 10,000 rows');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('10,000', $e->getMessage());
        }
        self::assertSame(10000, BatchFileParser::parse(implode("\n", array_map(static fn (int $i): string => "u$i@example.org", range(1, 10000))), 'ok.txt')['counts']['imported']);
        try {
            BatchFileParser::parse(str_repeat('x', BatchFileParser::MAX_BYTES + 1), 'huge.txt');
            self::fail('too large');
        } catch (DomainRuleViolation) {
        }
    }

    /** @return array{0: Client, 1: string, 2: User} */
    private function batch(string $content, AddressBatchPurpose $purpose = AddressBatchPurpose::Validation): array
    {
        $client = $this->newClient();
        $admin = $this->newUser(false, null, roleKey: 'ADMIN');
        $id = $this->container()->get(AddressBatchService::class)->import($this->reload($client), BatchFileParser::parse($content, 'old-list.txt'),
            'old-list.txt', 'Old list', 'the 2019 export', 'newsletter archive', $purpose, 'news.example.org', $this->reload($admin));

        return [$client, $id, $admin];
    }

    /** Fills in validator results the way the validator records them. */
    private static function validated(string $batchId, string $address, string $classification, array $extra = []): void
    {
        Db::owner()->executeStatement(<<<'SQL'
            UPDATE validation_addresses va SET processing_state = 'done', overall_classification = ?, confidence = 'medium', checked_at = now(),
                   syntax_status = 'valid', diagnostic_code = 'test', diagnostic_text = 'Recorded by the test.',
                   is_role = ?, is_disposable = ?, is_catch_all_or_accept_all = ?, is_domain_typo_suspected = ?,
                   suggested_address = ?, suggestion_reason_code = ?, suggestion_confidence = ?
              FROM address_batch_entries e WHERE e.validation_address_id = va.id AND e.batch_id = ? AND e.normalized_address = ?
            SQL, [$classification, $extra['role'] ?? false, $extra['disposable'] ?? false, $extra['accept_all'] ?? false,
            isset($extra['suggest']), $extra['suggest'] ?? null, isset($extra['suggest']) ? 'transposition' : null,
            isset($extra['suggest']) ? 'high' : null, $batchId, $address], [1 => \Doctrine\DBAL\ParameterType::BOOLEAN, 2 => \Doctrine\DBAL\ParameterType::BOOLEAN,
            3 => \Doctrine\DBAL\ParameterType::BOOLEAN, 4 => \Doctrine\DBAL\ParameterType::BOOLEAN]);
    }

    public function testImportValidateAndTheSeparateStateDimensions(): void
    {
        [$client, $id, $admin] = $this->batch("good@example.org\nbad@example.org\nrisky@example.org\nbounced@example.org\ninfo@example.org\nuser@gmial.com\nunknown@example.org\nnot-an-address\ngood@example.org\n");
        $o = Db::owner();
        $row = array_intersect_key($o->fetchAssociative('SELECT * FROM address_batches WHERE id = ?', [$id]),
            array_flip(['data_rows', 'imported_count', 'duplicate_count', 'malformed_count', 'file_format', 'source']));
        ksort($row);
        self::assertSame(['data_rows' => 9, 'duplicate_count' => 1, 'file_format' => 'txt', 'imported_count' => 7, 'malformed_count' => 1, 'source' => 'newsletter archive'], $row);
        self::assertSame(1, (int) $o->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'address_batch.imported' AND target_id = ?", [$id]));

        $service = $this->container()->get(AddressBatchService::class);
        self::assertSame(7, $service->validate($id, $this->reload($client), $this->reload($admin)));
        self::assertSame(7, (int) $o->fetchOne("SELECT count(*) FROM address_batch_entries e JOIN validation_addresses va ON va.id = e.validation_address_id
            JOIN validation_jobs j ON j.id = va.job_id WHERE e.batch_id = ? AND j.client_id = ? AND va.external_address_reference = e.id::text", [$id, $client->getId()->toRfc4122()]));
        $read = $this->container()->get(BatchReadModel::class);
        self::assertSame(['linked' => 7, 'done' => 0], array_intersect_key($read->progress($id), ['linked' => 1, 'done' => 1]));

        self::validated($id, 'good@example.org', 'deliverable');
        self::validated($id, 'bad@example.org', 'undeliverable');
        self::validated($id, 'risky@example.org', 'risky', ['accept_all' => true]);
        self::validated($id, 'bounced@example.org', 'probably_deliverable');
        self::validated($id, 'info@example.org', 'probably_deliverable', ['role' => true]);
        self::validated($id, 'user@gmial.com', 'probably_deliverable', ['suggest' => 'user@gmail.com']);
        // unknown@example.org stays pending
        $o->insert('suppressions', ['id' => SchemaFixtures::id(), 'client_id' => null, 'address_or_domain' => 'bounced@example.org',
            'scope_type' => 'address', 'reason' => 'hard_bounce', 'created_at' => gmdate('Y-m-d H:i:s')]);

        $s = $read->summary($id);
        self::assertSame(['entries' => 9, 'imported' => 7, 'duplicate' => 1, 'malformed' => 1, 'pending' => 1, 'valid' => 4, 'invalid' => 1,
            'risky' => 1, 'typo_suspected' => 1, 'role_account' => 1, 'accept_all' => 1, 'suppressed' => 1],
            array_intersect_key($s, array_flip(['entries', 'imported', 'duplicate', 'malformed', 'pending', 'valid', 'invalid', 'risky',
                'typo_suspected', 'role_account', 'accept_all', 'suppressed'])));
        // eligible: good, info (role is only a warning flag); not bounced (suppressed), not the typo (undecided), not risky, not pending
        self::assertSame(2, $s['eligible']);
        $byAddr = array_column($read->entries($id, 'imported', 0, 100), null, 'normalized_address');
        self::assertSame(['valid', 'eligible', 'unknown', 'not_sent', 'not_opened'], [$byAddr['good@example.org']['validation_result'], $byAddr['good@example.org']['eligibility'],
            $byAddr['good@example.org']['consent_state'], $byAddr['good@example.org']['delivery_state'], $byAddr['good@example.org']['engagement']]);
        self::assertSame(['valid', 'blocked_hard_bounce'], [$byAddr['bounced@example.org']['validation_result'], $byAddr['bounced@example.org']['eligibility']],
            'one address: valid and, at the same time, blocked by a hard bounce');
        self::assertSame(['role_account'], $byAddr['info@example.org']['flags']);
        self::assertSame('pending_review', $byAddr['user@gmial.com']['eligibility']);
        self::assertStringContainsString('did they mean user@gmail.com', $byAddr['user@gmial.com']['explanation']);
        self::assertStringContainsString('not proof', $byAddr['bounced@example.org']['explanation']);

        // Typo: accepting adds a correction (validated later); the original is excluded. Review: include the risky one.
        $service->decideTypo($id, (string) $byAddr['user@gmial.com']['id'], true, $this->reload($admin));
        self::assertSame(['user@gmail.com', 'imported'], array_values($o->fetchAssociative(
            'SELECT normalized_address, outcome FROM address_batch_entries WHERE corrects_entry_id = ?', [$byAddr['user@gmial.com']['id']])));
        $service->review($id, [(string) $byAddr['risky@example.org']['id']], BatchReviewDecision::Include, $this->reload($admin));
        $s = $read->summary($id);
        self::assertSame(3, $s['eligible']);
        self::assertSame(1, $service->validate($id, $this->reload($client), $this->reload($admin)), 'only the correction is validated now');

        // Reports: one CSV per filter, formula-looking cells neutralised.
        $csv = $read->csv($id, 'all');
        self::assertStringStartsWith('row,original,address,import_outcome,validation_result,flags,suggestion,eligibility,consent,delivery_state,engagement,suppression,explanation', $csv);
        self::assertSame(10, substr_count(trim($csv), "\n"), 'the header line and one line per entry (9 rows and the correction)');
        self::assertStringContainsString('blocked_hard_bounce', $read->csv($id, 'suppressed'));
    }

    public function testSendsSelectOnlyEligibleAddressesInStagesAndWaitWhileHeld(): void
    {
        [$client, $id, $admin] = $this->batch("one@example.org\ntwo@example.org\nthree@example.org\nnope@example.org\n", AddressBatchPurpose::Repermission);
        $service = $this->container()->get(AddressBatchService::class);
        $service->validate($id, $this->reload($client), $this->reload($admin));
        foreach (['one', 'two', 'three'] as $a) {
            self::validated($id, "$a@example.org", 'deliverable');
        }
        self::validated($id, 'nope@example.org', 'undeliverable');
        $domain = $this->verifiedDomain($client);
        $sender = $this->container()->get(BatchSender::class);
        $read = $this->container()->get(BatchReadModel::class);
        $form = ['stage' => 'controlled', 'count' => 2, 'subject' => 'Still interested?', 'text_body' => 'Answer here: {{response_url}}',
            'sender_email' => 'news@'.$domain->getDomain()];

        $preview = $sender->preview($read->batch($id), $this->reload($client), $form);
        self::assertStringContainsString('compliance approval', implode(' ', $preview['blockers']), 'the compliance gate');
        try {
            $sender->send($read->batch($id), $this->reload($client), $form, $this->reload($admin));
            self::fail('sent without approval');
        } catch (DomainRuleViolation) {
        }
        try {
            BatchSender::content(['subject' => 's', 'text_body' => 'no link'], true);
            self::fail('a re-permission message needs the answer link');
        } catch (DomainRuleViolation) {
        }
        $service->approveCompliance($id, 'Approved by the data protection officer, ref DPO-2026-17', $this->reload($admin));

        $jobId = $sender->send($read->batch($id), $this->reload($client), $form, $this->reload($admin));
        $o = Db::owner();
        self::assertSame(['queued', 'subscription', '<news.example.org>', 2], array_values($o->fetchAssociative(
            'SELECT status, message_class, list_id, total_recipients FROM send_jobs WHERE id = ?', [$jobId])));
        $rcpts = $o->fetchAllAssociative('SELECT normalized_address, text_body, unsubscribe_url FROM send_job_recipients WHERE send_job_id = ?', [$jobId]);
        self::assertNotContains('nope@example.org', array_column($rcpts, 'normalized_address'), 'never an invalid address');
        foreach ($rcpts as $r) {
            self::assertMatchesRegularExpression('#^Answer here: https://smarthost\.localhost/p/[A-Za-z0-9_-]{43}$#', $r['text_body']);
            self::assertStringEndsWith('?choice=unsubscribe', $r['unsubscribe_url']);
            self::assertStringNotContainsString($r['normalized_address'], $r['unsubscribe_url'], 'no address in the link');
        }
        self::assertSame(0, (int) $o->fetchOne('SELECT count(*) FROM messages WHERE send_job_id = ?', [$jobId]), 'nothing is delivered by this request');
        self::assertSame(2, (int) $o->fetchOne("SELECT count(*) FROM address_batch_entries WHERE batch_id = ? AND consent_state = 'unconfirmed' AND response_token_hash IS NOT NULL", [$id]));

        $s = $read->summary($id);
        self::assertSame([3, 1], [$s['eligible'], $s['unsent_eligible']], 'each address at most once per batch');
        $full = $sender->preview($read->batch($id), $this->reload($client), ['stage' => 'full'] + $form);
        self::assertCount(1, $full['recipients']);
        self::assertSame(2, $read->sendReport($id, $jobId)['waiting'], 'the report shows recipients still waiting for the delivery daemon');

        // Seed tests go only to typed-in addresses the administrator owns.
        $seed = ['stage' => 'seed', 'seed_addresses' => 'me@example.net', 'subject' => 'Seed', 'text_body' => 'Hi {{response_url}}', 'sender_email' => 'news@'.$domain->getDomain()];
        try {
            $sender->send($read->batch($id), $this->reload($client), $seed, $this->reload($admin));
            self::fail('ownership not confirmed');
        } catch (DomainRuleViolation) {
        }
        $seedJob = $sender->send($read->batch($id), $this->reload($client), $seed + ['i_own_these' => true], $this->reload($admin));
        self::assertSame(['me@example.net'], $o->fetchFirstColumn('SELECT normalized_address FROM send_job_recipients WHERE send_job_id = ?', [$seedJob]));
        self::assertStringContainsString('/p/seed-test', (string) $o->fetchOne('SELECT text_body FROM send_job_recipients WHERE send_job_id = ?', [$seedJob]));
        self::assertSame(['address_batch.send_created', 'address_batch.send_created'], $o->fetchFirstColumn(
            "SELECT action FROM audit_log WHERE target_id = ? AND action = 'address_batch.send_created'", [$id]));
    }

    public function testTheDashboardFlow(): void
    {
        $client = $this->newClient(\App\Enum\ClientStatus::Active, 'Batch Co');
        $admin = $this->newUser(false, null, roleKey: 'ADMIN');
        $this->signIn($admin);
        $index = $this->crawler('/dashboard/operator/batches');
        $token = $index->filter('form[action="/dashboard/operator/batches/preview"] input[name="_token"]')->attr('value');
        $file = tempnam(sys_get_temp_dir(), 'batch');
        file_put_contents($file, "first,mail,city\nAnn,ann@example.org,Rome\nBen,ann@example.org,Oslo\nCy,=cmd|' /c calc'!A0,Paris\n");
        $upload = new \Symfony\Component\HttpFoundation\File\UploadedFile($file, 'members.csv', 'text/csv', null, true);
        $crawler = $this->browser->request('POST', '/dashboard/operator/batches/preview', ['_token' => $token, 'client' => $client->getId()->toRfc4122(),
            'name' => 'Members', 'purpose' => 'validation', 'list_id' => 'members.example.org'], ['file' => $upload]);
        self::assertSame(200, $this->browser->getResponse()->getStatusCode(), substr((string) $this->browser->getResponse()->getContent(), 0, 400));
        $text = $crawler->filter('body')->text();
        self::assertMatchesRegularExpression('/Addresses to import \(unique\)\s*1\b/', $text);
        self::assertStringContainsString('Exact duplicates', $text);
        self::assertSame(0, (int) Db::owner()->fetchOne("SELECT count(*) FROM address_batches WHERE name = 'Members' AND client_id = ?", [$client->getId()->toRfc4122()]), 'nothing stored at preview');

        $form = $crawler->filter('form[action="/dashboard/operator/batches/import"]')->form();
        $this->browser->submit($form);
        $location = (string) $this->browser->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/dashboard/operator/batches/[0-9a-f-]{36}$#', $location);
        $id = substr($location, \strlen('/dashboard/operator/batches/'));
        self::assertSame(['csv', 'mail', 3, 1, 1, 1], array_values(Db::owner()->fetchAssociative(
            'SELECT file_format, email_column, data_rows, imported_count, duplicate_count, malformed_count FROM address_batches WHERE id = ?', [$id])));

        $page = self::text($this->page($location));
        foreach (['Validation', 'Send eligibility', 'Compliance approval', 'Prepare a send'] as $needle) {
            self::assertStringContainsString($needle, $page);
        }
        $this->submit($location, "/dashboard/operator/batches/$id/validate");
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM address_batch_entries WHERE batch_id = ? AND validation_address_id IS NOT NULL', [$id]));
        self::assertSame(['linked' => 1, 'done' => 0], array_intersect_key(json_decode((string) $this->page("$location/progress")->getContent(), true), ['linked' => 1, 'done' => 1]));
        self::assertStringContainsString('ann@example.org', self::text($this->page("$location/entries?filter=pending")));
        $csv = (string) $this->page("$location/export.csv?filter=malformed")->getContent();
        self::assertStringContainsString("'=cmd", $csv, 'spreadsheet formulas are neutralised');
        $send = self::text($this->page("$location/send"));
        self::assertStringContainsString('Seed test', $send);
        self::assertSame(404, $this->page('/dashboard/operator/batches/'.$id.'/entries/00000000-0000-0000-0000-000000000000')->getStatusCode());
    }
}
