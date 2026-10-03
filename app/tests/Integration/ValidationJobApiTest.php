<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;

/** POST/GET /v1/validation-jobs[...]: job creation primitives only - nothing is validated in Phase 2. */
final class ValidationJobApiTest extends ApiTestCase
{
    private function create(string $key, array $body): array
    {
        $r = $this->api('POST', '/v1/validation-jobs', $key, $body, ['Idempotency-Key' => self::key()]);
        $job = $this->assertContract($r, 202, '/validation-jobs', 'post');
        self::assertSame('/v1/validation-jobs/'.$job['id'], $r->headers->get('Location'));

        return $job;
    }

    public function testSingleAddressJobInitialState(): void
    {
        [, $key] = $this->newApiClient();
        $job = $this->create($key, ['external_reference' => 'crm-export-7', 'addresses' => [['address' => 'someone@example.com', 'external_address_reference' => 'row-1']]]);
        self::assertSame('queued', $job['status']);
        self::assertSame(1, $job['total_addresses']);
        self::assertSame(0, $job['processed_count']);
        self::assertSame('crm-export-7', $job['external_reference']);
        self::assertNull($job['started_at']);
        self::assertSame(['deliverable' => 0, 'probably_deliverable' => 0, 'undeliverable' => 0, 'temporarily_unverifiable' => 0, 'unknown' => 0, 'risky' => 0],
            $job['classification_counts']);

        $row = Db::owner()->fetchAssociative('SELECT * FROM validation_addresses WHERE job_id = ?', [$job['id']]);
        self::assertSame('pending', $row['processing_state']);
        self::assertSame(0, (int) $row['attempt_count']);
        self::assertSame('row-1', $row['external_address_reference']);
        foreach (['normalized_address', 'syntax_status', 'domain_status', 'smtp_status', 'overall_classification', 'checked_at', 'claimed_by', 'lease_expires_at'] as $col) {
            self::assertNull($row[$col], "$col must be left to the validator");
        }
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM validation_evidence e JOIN validation_addresses a ON a.id = e.validation_address_id WHERE a.job_id = ?', [$job['id']]));

        $read = $this->assertContract($this->api('GET', '/v1/validation-jobs/'.$job['id'], $key), 200, '/validation-jobs/{id}', 'get');
        self::assertSame($job, $read);
    }

    public function testOriginalAddressesArePreservedVerbatimAndInOrder(): void
    {
        [, $key] = $this->newApiClient();
        $inputs = ['  Spaced@Example.COM ', 'John@example.com', 'john@example.com', 'John@example.com', 'not an address', 'Jöhn@bücher.example', "tab\t@x.example"];
        $job = $this->create($key, ['addresses' => array_map(fn ($a) => ['address' => $a], $inputs)]);
        self::assertSame(\count($inputs), $job['total_addresses'], 'duplicates are permitted and validated individually');
        $page = $this->assertContract($this->api('GET', "/v1/validation-jobs/{$job['id']}/addresses", $key), 200, '/validation-jobs/{id}/addresses', 'get');
        self::assertSame($inputs, array_column($page['data'], 'original_address'));
        self::assertSame(array_fill(0, \count($inputs), null), array_column($page['data'], 'external_address_reference'));
        self::assertNull($page['pagination']['next_cursor']);
    }

    public function testTenThousandAddressesIsTheBoundary(): void
    {
        [, $key] = $this->newApiClient();
        $addresses = array_map(fn ($i) => ['address' => "user$i@example.com", 'external_address_reference' => "ref-$i"], range(1, 10000));
        $job = $this->create($key, ['addresses' => $addresses]);
        self::assertSame(10000, $job['total_addresses']);
        self::assertSame(10000, (int) Db::owner()->fetchOne('SELECT count(*) FROM validation_addresses WHERE job_id = ?', [$job['id']]));

        $addresses[] = ['address' => 'one-too-many@example.com'];
        $r = $this->api('POST', '/v1/validation-jobs', $key, ['addresses' => $addresses], ['Idempotency-Key' => self::key()]);
        $problem = $this->assertProblem($r, 422, 'validation-error');
        self::assertSame('/addresses', $problem['errors'][0]['pointer']);
    }

    public function testPaginationFollowsSubmissionOrder(): void
    {
        [, $key] = $this->newApiClient();
        $job = $this->create($key, ['addresses' => array_map(fn ($i) => ['address' => "u$i@example.com"], range(1, 250))]);
        $seen = [];
        $cursor = null;
        do {
            $uri = "/v1/validation-jobs/{$job['id']}/addresses?limit=100".(null === $cursor ? '' : '&cursor='.$cursor);
            $page = $this->assertContract($this->api('GET', $uri, $key), 200, '/validation-jobs/{id}/addresses', 'get');
            self::assertSame(100, $page['pagination']['limit']);
            $seen = array_merge($seen, array_column($page['data'], 'original_address'));
            $cursor = $page['pagination']['next_cursor'];
        } while (null !== $cursor);
        self::assertSame(array_map(fn ($i) => "u$i@example.com", range(1, 250)), $seen);

        $filtered = $this->assertContract($this->api('GET', "/v1/validation-jobs/{$job['id']}/addresses?overall_classification=deliverable", $key),
            200, '/validation-jobs/{id}/addresses', 'get');
        self::assertSame([], $filtered['data'], 'nothing is classified before the validator runs');
    }

    public function testInvalidQueryParametersAre400(): void
    {
        [, $key] = $this->newApiClient();
        $job = $this->create($key, ['addresses' => [['address' => 'a@example.com']]]);
        foreach (['limit=0', 'limit=1001', 'limit=abc', 'cursor=%25%25%25', 'cursor=eyJ4IjoxfQ', 'overall_classification=valid'] as $q) {
            $this->assertProblem($this->api('GET', "/v1/validation-jobs/{$job['id']}/addresses?$q", $key), 400);
        }
    }

    public function testRequestValidation(): void
    {
        [, $key] = $this->newApiClient();
        $post = fn ($body, $headers = []) => $this->api('POST', '/v1/validation-jobs', $key, $body, ['Idempotency-Key' => self::key()] + $headers);
        $this->assertProblem($post(['addresses' => []]), 422, 'validation-error');
        $this->assertProblem($post([]), 422, 'validation-error');
        $this->assertProblem($post(['addresses' => [['address' => '']]]), 422, 'validation-error');
        $this->assertProblem($post(['addresses' => [['address' => str_repeat('a', 513)]]]), 422, 'validation-error');
        $this->assertProblem($post(['addresses' => [['address' => 'a@b.c', 'note' => 'x']]]), 422, 'validation-error');
        $this->assertProblem($post(['addresses' => ['a@b.c']]), 422, 'validation-error');
        $this->assertProblem($post('{"addresses": ['), 400, 'malformed-json');
        $this->assertProblem($post('[1,2]'), 422, 'validation-error');
        $this->assertProblem($post('{"addresses":[{"address":"a@b.c"}]}', ['Content-Type' => 'text/plain']), 400, 'unsupported-media-type');
    }

    public function testBodiesOverTheLimitAre413(): void
    {
        $this->withEnv(['APP_API_MAX_REQUEST_BYTES' => '2048'], function (): void {
            [, $key] = $this->newApiClient();
            $body = ['addresses' => array_map(fn ($i) => ['address' => "user$i@example.com"], range(1, 200))];
            $this->assertProblem($this->api('POST', '/v1/validation-jobs', $key, $body, ['Idempotency-Key' => self::key()]), 413, 'payload-too-large');
        });
    }
}
