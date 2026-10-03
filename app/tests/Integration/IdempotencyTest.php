<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;

/**
 * api.idempotency: same key + same body replays the original response; same key
 * + different body is 422; same key while the first request is in flight is 409;
 * submit is naturally idempotent. Includes real concurrent retries from separate
 * processes.
 */
final class IdempotencyTest extends ApiTestCase
{
    public function testValidationJobRetryReplaysTheOriginalResponse(): void
    {
        [, $key] = $this->newApiClient();
        $idem = self::key();
        $first = $this->api('POST', '/v1/validation-jobs', $key, ['external_reference' => 'r', 'addresses' => [['address' => 'a@example.com']]], ['Idempotency-Key' => $idem]);
        $body = $this->assertContract($first, 202, '/validation-jobs', 'post');
        self::assertNull($first->headers->get('Idempotent-Replayed'));

        // Same body with different key order and whitespace.
        $retry = $this->api('POST', '/v1/validation-jobs', $key, "{ \"addresses\": [ {\"address\": \"a@example.com\"} ], \"external_reference\": \"r\" }", ['Idempotency-Key' => $idem]);
        self::assertSame($body, $this->assertContract($retry, 202, '/validation-jobs', 'post'));
        self::assertSame('true', $retry->headers->get('Idempotent-Replayed'));
        self::assertSame($first->headers->get('Location'), $retry->headers->get('Location'));
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM validation_jobs WHERE id = ?', [$body['id']]));
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM validation_addresses WHERE job_id = ?', [$body['id']]));
    }

    public function testMismatchedBodyIsRejected(): void
    {
        [, $key] = $this->newApiClient();
        $idem = self::key();
        $this->api('POST', '/v1/validation-jobs', $key, ['addresses' => [['address' => 'a@example.com']]], ['Idempotency-Key' => $idem]);
        $r = $this->api('POST', '/v1/validation-jobs', $key, ['addresses' => [['address' => 'b@example.com']]], ['Idempotency-Key' => $idem]);
        $this->assertProblem($r, 422, 'idempotency-key-reused');
    }

    public function testKeyHeaderIsRequiredAndValidated(): void
    {
        [, $key] = $this->newApiClient();
        $body = ['addresses' => [['address' => 'a@example.com']]];
        $this->assertProblem($this->api('POST', '/v1/validation-jobs', $key, $body), 400, 'missing-idempotency-key');
        $this->assertProblem($this->api('POST', '/v1/validation-jobs', $key, $body, ['Idempotency-Key' => 'short']), 400, 'invalid-idempotency-key');
        $this->assertProblem($this->api('POST', '/v1/validation-jobs', $key, $body, ['Idempotency-Key' => 'has space key']), 400, 'invalid-idempotency-key');
        $this->assertProblem($this->api('POST', '/v1/send-jobs', $key, ['x' => 1]), 400, 'missing-idempotency-key');
    }

    public function testInFlightRequestWithSameKeyIsAConflict(): void
    {
        [$client, $key] = $this->newApiClient();
        $idem = self::key();
        // Another request holding the same idempotency lock (as the first request would).
        $other = Db::newApp();
        $other->beginTransaction();
        self::assertTrue((bool) $other->fetchOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0))',
            ['validation-jobs|'.$client->getId()->toRfc4122().'|'.$idem]));
        $body = ['addresses' => [['address' => 'a@example.com']]];
        $this->assertProblem($this->api('POST', '/v1/validation-jobs', $key, $body, ['Idempotency-Key' => $idem]), 409, 'idempotency-in-progress');
        $other->rollBack();
        $other->close();
        self::assertSame(202, $this->api('POST', '/v1/validation-jobs', $key, $body, ['Idempotency-Key' => $idem])->getStatusCode());
    }

    public function testConcurrentRetriesCreateExactlyOneJob(): void
    {
        [$client, $key] = $this->newApiClient();
        $results = $this->concurrent(8, $key, 'POST', '/v1/validation-jobs',
            ['addresses' => array_map(fn ($i) => ['address' => "user$i@example.com"], range(1, 2000))]);
        $ids = [];
        foreach ($results as $r) {
            self::assertContains($r['status'], [202, 409], json_encode($r));
            if (202 === $r['status']) {
                $ids[] = $r['body']['id'];
            }
        }
        self::assertNotEmpty($ids);
        self::assertCount(1, array_unique($ids), 'all successful responses describe the same job');
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM validation_jobs WHERE client_id = ?', [$client->getId()->toRfc4122()]));
        self::assertSame(2000, (int) Db::owner()->fetchOne('SELECT count(*) FROM validation_addresses WHERE job_id = ?', [$ids[0]]));
    }

    public function testConcurrentSendJobCreationAndBatchUpload(): void
    {
        [$client, $key] = $this->newApiClient();
        $domain = $this->verifiedDomain($client);
        $results = $this->concurrent(6, $key, 'POST', '/v1/send-jobs', ['external_reference' => 'c', 'message_class' => 'transactional',
            'sender_identity' => ['email' => 'a@'.$domain->getDomain()]]);
        $created = array_values(array_filter($results, fn ($r) => 201 === $r['status']));
        self::assertNotEmpty($created);
        self::assertCount(1, array_unique(array_map(fn ($r) => $r['body']['id'], $created)));
        foreach ($results as $r) {
            self::assertContains($r['status'], [201, 409]);
        }
        $jobId = $created[0]['body']['id'];

        $results = $this->concurrent(6, $key, 'POST', "/v1/send-jobs/$jobId/recipients", ['recipients' => self::recipients(0, 500)]);
        $accepted = array_values(array_filter($results, fn ($r) => 201 === $r['status']));
        self::assertCount(1, array_unique(array_map(fn ($r) => $r['body']['batch_id'], $accepted)));
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM send_job_recipient_batches WHERE send_job_id = ?', [$jobId]));
        self::assertSame(500, (int) Db::owner()->fetchOne('SELECT total_recipients FROM send_jobs WHERE id = ?', [$jobId]));
    }

    public function testRecipientBatchRetryReplaysTheOriginalResult(): void
    {
        [$client, $key] = $this->newApiClient();
        $job = $this->createSendJob($key, $this->verifiedDomain($client));
        $k1 = self::key();
        $first = $this->assertContract($this->addBatch($key, $job, self::recipients(0, 3), $k1), 201, '/send-jobs/{id}/recipients', 'post');
        self::assertSame(['accepted_count' => 3, 'total_recipients' => 3], array_diff_key($first, ['batch_id' => 1]));
        $second = $this->assertContract($this->addBatch($key, $job, self::recipients(3, 2)), 201, '/send-jobs/{id}/recipients', 'post');
        self::assertSame(5, $second['total_recipients']);

        $retry = $this->addBatch($key, $job, self::recipients(0, 3), $k1);
        self::assertSame($first, $this->assertContract($retry, 201, '/send-jobs/{id}/recipients', 'post'), 'original running total, not the current one');
        self::assertSame('true', $retry->headers->get('Idempotent-Replayed'));
        self::assertSame(5, (int) Db::owner()->fetchOne('SELECT count(*) FROM send_job_recipients WHERE send_job_id = ?', [$job]));

        $this->assertProblem($this->addBatch($key, $job, self::recipients(0, 4), $k1), 422, 'idempotency-key-reused');

        // A batch retried after submit still replays (the job is no longer collecting).
        self::assertSame(202, $this->api('POST', "/v1/send-jobs/$job/submit", $key)->getStatusCode());
        self::assertSame($first, self::json($this->addBatch($key, $job, self::recipients(0, 3), $k1)));
        // The same batch key on another job is a different scope.
        $job2 = $this->createSendJob($key, $this->verifiedDomain($client));
        self::assertNull($this->addBatch($key, $job2, self::recipients(0, 3), $k1)->headers->get('Idempotent-Replayed'));
    }

    public function testSendJobCreationRetry(): void
    {
        [$client, $key] = $this->newApiClient();
        $domain = $this->verifiedDomain($client);
        $idem = self::key();
        $body = ['external_reference' => 'x', 'message_class' => 'transactional', 'sender_identity' => ['email' => 'a@'.$domain->getDomain()]];
        $first = $this->api('POST', '/v1/send-jobs', $key, $body, ['Idempotency-Key' => $idem]);
        $retry = $this->api('POST', '/v1/send-jobs', $key, $body, ['Idempotency-Key' => $idem]);
        self::assertSame(self::json($first), $this->assertContract($retry, 201, '/send-jobs', 'post'));
        self::assertSame('true', $retry->headers->get('Idempotent-Replayed'));
        $this->assertProblem($this->api('POST', '/v1/send-jobs', $key, ['tracking' => ['opens' => true]] + $body, ['Idempotency-Key' => $idem]), 422, 'idempotency-key-reused');
    }

    public function testSubmitRetryIsSafe(): void
    {
        [$client, $key] = $this->newApiClient();
        $job = $this->createSendJob($key, $this->verifiedDomain($client));
        $this->addBatch($key, $job, self::recipients(0, 2));
        $first = $this->assertContract($this->api('POST', "/v1/send-jobs/$job/submit", $key), 202, '/send-jobs/{id}/submit', 'post');
        $again = $this->assertContract($this->api('POST', "/v1/send-jobs/$job/submit", $key), 202, '/send-jobs/{id}/submit', 'post');
        self::assertSame($first, $again);
        self::assertSame('queued', $again['status']);
    }

    public function testConcurrentSubmitsSealOnce(): void
    {
        [$client, $key] = $this->newApiClient();
        $job = $this->createSendJob($key, $this->verifiedDomain($client));
        $this->addBatch($key, $job, self::recipients(0, 2));
        $results = $this->concurrent(6, $key, 'POST', "/v1/send-jobs/$job/submit", null);
        $queuedAt = array_unique(array_map(fn ($r) => $r['body']['queued_at'], $results));
        foreach ($results as $r) {
            self::assertSame(202, $r['status']);
        }
        self::assertCount(1, $queuedAt);
    }

    /**
     * Fires $n identical requests (same Idempotency-Key) from separate PHP processes
     * released at the same instant.
     *
     * @return list<array{status: int, replayed: ?string, body: mixed}>
     */
    private function concurrent(int $n, string $apiKey, string $method, string $uri, ?array $body): array
    {
        $spec = tempnam(sys_get_temp_dir(), 'req');
        file_put_contents($spec, json_encode(['key' => $apiKey, 'method' => $method, 'uri' => $uri, 'ip' => '10.200.0.1',
            'idempotency_key' => self::key(), 'body' => null === $body ? '' : json_encode($body)]));
        $start = (string) (microtime(true) + 3.0);
        $procs = [];
        for ($i = 0; $i < $n; ++$i) {
            $procs[] = proc_open(['php', \dirname(__DIR__).'/bin/request.php', $spec, $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        $out = [];
        foreach ($procs as $i => $p) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);
            proc_close($p);
            $line = trim((string) $stdout);
            self::assertNotSame('', $line, "worker $i failed: $stderr");
            $lines = explode("\n", $line);
            $out[] = json_decode((string) end($lines), true, 512, \JSON_THROW_ON_ERROR);
        }
        unlink($spec);

        return $out;
    }
}
