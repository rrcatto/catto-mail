<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditActor;
use App\Entity\Client;
use App\Enum\ClientStatus;
use App\Suppression\SuppressionAdministration;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;
use Symfony\Component\Uid\Uuid;

/**
 * D-30 recipient global opt-outs through /v1/global-suppressions: the operator
 * capability, normalisation, idempotency, provenance, lifting and audit, and the
 * boundary that keeps ordinary unsubscribes out of Smarthost.
 */
final class GlobalSuppressionApiTest extends ApiTestCase
{
    /** @return array{0: Client, 1: string} a client with the capability and a key */
    private function trustedClient(): array
    {
        [$client, $key] = $this->newApiClient();
        $this->service(SuppressionAdministration::class)->setGlobalSuppressionCapability($this->reload($client), true, self::actor(), 'test');

        return [$client, $key];
    }

    private function optOut(string $key, array $body, ?string $idem = null): \Symfony\Component\HttpFoundation\Response
    {
        return $this->api('POST', '/v1/global-suppressions', $key, $body, ['Idempotency-Key' => $idem ?? self::key()]);
    }

    private static function address(): string
    {
        return 'Opt.Out'.bin2hex(random_bytes(4)).'@Example.COM';
    }

    public function testClientsWithoutTheCapabilityAreForbidden(): void
    {
        [, $key] = $this->newApiClient();
        $this->assertProblem($this->optOut($key, ['email_address' => self::address()]), 403, 'forbidden');
        // Checked before the body: even an invalid body is 403.
        $this->assertProblem($this->optOut($key, ['email_address' => 'x', 'reason' => 'hard_bounce']), 403, 'forbidden');
        self::assertSame(0, (int) Db::owner()->fetchOne("SELECT count(*) FROM suppressions WHERE reason = 'recipient_global_opt_out' AND source_client_id IN (SELECT client_id FROM api_keys WHERE key_prefix = ?)", [substr($key, 0, 12)]));

        // Existing clients never received the capability by default.
        self::assertFalse($this->newClient()->canSubmitGlobalSuppressions());
    }

    public function testSuspendedOrPendingTrustedClientsAreForbidden(): void
    {
        foreach ([ClientStatus::Suspended, ClientStatus::PendingApproval] as $status) {
            [$client, $key] = $this->trustedClient();
            $this->service(\App\Client\AccountAdministration::class)->setClientStatus($this->reload($client), $status, self::actor());
            $this->assertProblem($this->optOut($key, ['email_address' => self::address()]), 403, 'forbidden');
        }
    }

    public function testTrustedClientCreatesAGlobalOptOutWithProvenance(): void
    {
        [$client, $key] = $this->trustedClient();
        $address = '  '.self::address().' ';
        $res = $this->optOut($key, ['email_address' => $address, 'external_reference' => 'crm-42']);
        $body = $this->assertContract($res, 201, '/global-suppressions', 'post');
        $normalized = trim(explode('@', $address)[0]).'@example.com';
        self::assertSame($normalized, $body['email_address'], 'D-18: local part preserved, domain lower-cased, whitespace trimmed');
        self::assertSame('recipient_global_opt_out', $body['reason']);
        self::assertSame('active', $body['status']);
        self::assertSame('crm-42', $body['external_reference']);
        self::assertSame('/v1/global-suppressions/'.$body['id'], $res->headers->get('Location'));

        $row = Db::owner()->fetchAssociative('SELECT * FROM suppressions WHERE id = ?', [$body['id']]);
        self::assertNull($row['client_id'], 'global: client_id is NULL');
        self::assertSame($client->getId()->toRfc4122(), $row['source_client_id'], 'the reporter is kept separately');
        self::assertSame('address', $row['scope_type']);
        self::assertNull($row['expires_at']);
        self::assertNull($row['lifted_at']);
        $audit = Db::owner()->fetchAssociative("SELECT * FROM audit_log WHERE target_type = 'suppression' AND target_id = ?", [$body['id']]);
        self::assertSame('suppression.global_opt_out_created', $audit['action']);
        self::assertSame('api_key', $audit['actor_type']);
        self::assertStringNotContainsString('shk_', (string) $audit['detail_json'], 'never a raw key in the audit log');

        $this->assertContract($this->api('GET', '/v1/global-suppressions/'.$body['id'], $key), 200, '/global-suppressions/{id}', 'get');
    }

    public function testCreationIsIdempotent(): void
    {
        [, $key] = $this->trustedClient();
        $address = self::address();
        $idem = self::key();
        $first = $this->assertContract($this->optOut($key, ['email_address' => $address], $idem), 201, '/global-suppressions', 'post');
        $replay = $this->optOut($key, ['email_address' => $address], $idem);
        self::assertSame($first['id'], $this->assertContract($replay, 201, '/global-suppressions', 'post')['id']);
        self::assertSame('true', $replay->headers->get('Idempotent-Replayed'));
        $this->assertProblem($this->optOut($key, ['email_address' => 'other'.$address], $idem), 422, 'idempotency-key-reused');

        // A new key for an address this client already opted out returns the existing row (200), no duplicate.
        $again = $this->optOut($key, ['email_address' => $address]);
        self::assertSame($first['id'], $this->assertContract($again, 200, '/global-suppressions', 'post')['id']);
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM suppressions WHERE reason = 'recipient_global_opt_out' AND address_or_domain = ?",
            [explode('@', $address)[0].'@example.com']));
    }

    public function testInFlightDuplicateIsAConflict(): void
    {
        [$client, $key] = $this->trustedClient();
        $idem = self::key();
        $other = Db::newApp();
        $other->beginTransaction();
        try {
            self::assertTrue((bool) $other->fetchOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0))',
                ['global-suppressions|'.$client->getId()->toRfc4122().'|'.$idem]));
            $this->assertProblem($this->optOut($key, ['email_address' => self::address()], $idem), 409, 'idempotency-in-progress');
        } finally {
            $other->rollBack();
        }
    }

    public function testConcurrentReportsCreateExactlyOneRow(): void
    {
        [$client, $key] = $this->trustedClient();
        $address = self::address();
        $normalized = explode('@', $address)[0].'@example.com';

        // The same Idempotency-Key from 8 processes at once: one row, the rest replays or 409.
        $same = self::key();
        $results = $this->concurrent($key, array_fill(0, 8, $same), ['email_address' => $address]);
        $ids = [];
        foreach ($results as $r) {
            self::assertContains($r['status'], [201, 409], json_encode($r));
            if (201 === $r['status']) {
                $ids[] = $r['body']['id'];
            }
        }
        self::assertCount(1, array_unique($ids));

        // 8 different keys for the same address at once: still one active row (201 once, else 200).
        $other = 'Second'.$address;
        $results = $this->concurrent($key, array_map(fn () => self::key(), range(1, 8)), ['email_address' => $other]);
        $statuses = array_count_values(array_map(fn ($r) => $r['status'], $results));
        self::assertSame(1, $statuses[201] ?? 0, json_encode($results));
        self::assertSame(7, $statuses[200] ?? 0, json_encode($results));
        self::assertCount(1, array_unique(array_map(fn ($r) => $r['body']['id'], $results)));
        foreach ([$normalized, explode('@', $other)[0].'@example.com'] as $a) {
            self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM suppressions WHERE reason = 'recipient_global_opt_out' AND source_client_id = ? AND address_or_domain = ?",
                [$client->getId()->toRfc4122(), $a]));
        }
    }

    /**
     * One request per idempotency key, each from its own PHP process and database
     * connection, released at the same instant (tests/bin/request.php).
     *
     * @param list<string> $idempotencyKeys
     *
     * @return list<array{status: int, replayed: ?string, body: array<string, mixed>}>
     */
    private function concurrent(string $apiKey, array $idempotencyKeys, array $body): array
    {
        $start = (string) (microtime(true) + 3.0);
        $procs = $pipes = $specs = [];
        foreach ($idempotencyKeys as $i => $idem) {
            $specs[$i] = tempnam(sys_get_temp_dir(), 'req');
            file_put_contents($specs[$i], json_encode(['key' => $apiKey, 'method' => 'POST', 'uri' => '/v1/global-suppressions',
                'ip' => '10.201.0.1', 'idempotency_key' => $idem, 'body' => json_encode($body)]));
            $procs[$i] = proc_open(['php', \dirname(__DIR__).'/bin/request.php', $specs[$i], $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        $out = [];
        foreach ($procs as $i => $proc) {
            $stdout = trim((string) stream_get_contents($pipes[$i][1]));
            $stderr = (string) stream_get_contents($pipes[$i][2]);
            proc_close($proc);
            unlink($specs[$i]);
            self::assertNotSame('', $stdout, "worker $i failed: $stderr");
            $lines = explode("\n", $stdout);
            $out[] = json_decode((string) end($lines), true, 512, \JSON_THROW_ON_ERROR);
        }

        return $out;
    }

    public function testOnlyTheOptOutReasonAndAddressScopeCanBeRequested(): void
    {
        [, $key] = $this->trustedClient();
        foreach ([['reason' => 'hard_bounce'], ['reason' => 'complaint'], ['scope_type' => 'domain'], ['client_id' => Uuid::v7()->toRfc4122()], ['domain' => 'example.com']] as $extra) {
            $p = $this->assertProblem($this->optOut($key, ['email_address' => self::address()] + $extra), 422, 'validation-error');
            self::assertNotEmpty($p['errors']);
        }
        $this->assertProblem($this->optOut($key, ['email_address' => 'not-an-address']), 422, 'validation-error');
        $this->assertProblem($this->optOut($key, ['email_address' => 'user@bad_domain']), 422, 'validation-error');
    }

    public function testOrdinaryUnsubscribeIsNotSmarthostState(): void
    {
        [, $key] = $this->trustedClient();
        // There is no list/unsubscribe resource in the API; such requests are unknown routes.
        foreach (['/v1/unsubscribes', '/v1/suppressions', '/v1/lists/news/unsubscribe'] as $path) {
            self::assertSame(404, $this->api('POST', $path, $key, ['email_address' => self::address()], ['Idempotency-Key' => self::key()])->getStatusCode(), $path);
        }
        // Sending a subscription job never creates a suppression by itself.
        self::assertSame(0, (int) Db::owner()->fetchOne("SELECT count(*) FROM suppressions WHERE reason NOT IN ('recipient_global_opt_out', 'hard_bounce', 'complaint', 'repeated_soft_bounce', 'operator_block', 'client_abuse_block')"));
    }

    public function testLiftByTheReportingClientKeepsHistoryAndIndependentSuppressions(): void
    {
        [$a, $keyA] = $this->trustedClient();
        [, $keyB] = $this->trustedClient();
        $address = self::address();
        $normalized = explode('@', $address)[0].'@example.com';
        $mine = $this->assertContract($this->optOut($keyA, ['email_address' => $address]), 201, '/global-suppressions', 'post');
        $theirs = $this->assertContract($this->optOut($keyB, ['email_address' => $address]), 201, '/global-suppressions', 'post');
        self::assertNotSame($mine['id'], $theirs['id'], 'independent reporters have independent rows');
        $hard = Uuid::v7()->toRfc4122();
        Db::owner()->insert('suppressions', ['id' => $hard, 'address_or_domain' => $normalized, 'scope_type' => 'address', 'reason' => 'hard_bounce']);

        // Another client can neither see nor lift A's opt-out.
        $this->assertProblem($this->api('GET', '/v1/global-suppressions/'.$mine['id'], $keyB), 404, 'not-found');
        $this->assertProblem($this->api('POST', '/v1/global-suppressions/'.$mine['id'].'/lift', $keyB), 404, 'not-found');
        // System suppressions are not reachable through the opt-out API.
        $this->assertProblem($this->api('POST', '/v1/global-suppressions/'.$hard.'/lift', $keyA), 404, 'not-found');

        $lifted = $this->assertContract($this->api('POST', '/v1/global-suppressions/'.$mine['id'].'/lift', $keyA), 200, '/global-suppressions/{id}/lift', 'post');
        self::assertSame('lifted', $lifted['status']);
        self::assertNotNull($lifted['lifted_at']);
        $again = $this->assertContract($this->api('POST', '/v1/global-suppressions/'.$mine['id'].'/lift', $keyA), 200, '/global-suppressions/{id}/lift', 'post');
        self::assertSame($lifted['lifted_at'], $again['lifted_at'], 'lifting is idempotent');

        $rows = Db::owner()->fetchAllKeyValue('SELECT id::text, (lifted_at IS NULL)::int FROM suppressions WHERE address_or_domain = ? ORDER BY id', [$normalized]);
        $expected = [$mine['id'] => 0, $theirs['id'] => 1, $hard => 1];
        ksort($expected);
        self::assertSame($expected, $rows,
            'the row is kept (lifted); the other opt-out and the hard bounce stay active');
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'suppression.global_opt_out_lifted' AND target_id = ?", [$mine['id']]),
            'lifted once, audited once');

        // A client that lost the capability cannot lift.
        $this->service(SuppressionAdministration::class)->setGlobalSuppressionCapability($this->reload($a), false, AuditActor::system('test'), 'revoked');
        $this->assertProblem($this->api('POST', '/v1/global-suppressions/'.$mine['id'].'/lift', $keyA), 403, 'forbidden');
    }

    public function testTenantFilterHidesOtherGlobalSuppressions(): void
    {
        [, $key] = $this->trustedClient();
        $other = Uuid::v7()->toRfc4122();
        Db::owner()->insert('suppressions', ['id' => $other, 'address_or_domain' => strtolower(self::address()), 'scope_type' => 'address', 'reason' => 'complaint']);
        $this->assertProblem($this->api('GET', '/v1/global-suppressions/'.$other, $key), 404, 'not-found');
    }
}
