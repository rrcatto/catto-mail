<?php

// Phase 3 end-to-end driver (run by infra/tests/phase3-test.sh in the throwaway
// test pod). Not a PHPUnit test: it brackets a real validator process run.
//
//   php tests/Phase3/e2e.php prepare /exchange/plan.json
//       creates clients and API keys, and validation jobs THROUGH THE /v1 API
//       (a mixed scenario job, a deterministic 10,000-address job, and a job of a
//       client that is then suspended);
//   php tests/Phase3/e2e.php verify /exchange/plan.json /exchange/stats.json
//       checks the results through the /v1 API (responses validated against the
//       OpenAPI contract) and the database; prints a JSON report; exit 1 on failure.
declare(strict_types=1);

use App\Api\OpenApiContract;
use App\Audit\AuditActor;
use App\Client\AccountAdministration;
use App\Enum\ClientStatus;
use App\Kernel;
use App\Security\ApiKeyManager;
use App\Tests\Support\Db;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const MIXED = [
    // address => expected overall_classification (fake DNS zone: validator/tests/e2e/zone.json)
    'someone@example.test' => 'deliverable',
    ' Someone.Else@EXAMPLE.TEST ' => 'deliverable',
    'reject-550@example.test' => 'undeliverable',
    'x@second.test' => 'deliverable',
    'x@multi.test' => 'deliverable',
    'x@accept-all.test' => 'risky',
    'x@block-all.test' => 'unknown',
    'tempfail-450@example.test' => 'temporarily_unverifiable',
    'timeout@example.test' => 'temporarily_unverifiable',
    'throttle-421@throttle.test' => 'temporarily_unverifiable',
    'x@nullmx.test' => 'undeliverable',
    'x@fallback.test' => 'deliverable',
    'x@nohost.test' => 'undeliverable',
    'x@absent.test' => 'undeliverable',
    'x@servfail.test' => 'temporarily_unverifiable',
    'x@tempmail.test' => 'risky',
    'x@gmial.com' => 'risky',
    'info@example.test' => 'deliverable',
    'not-an-address' => 'undeliverable',
    'x@[192.0.2.1]' => 'unknown',
    'jöhn@example.test' => 'deliverable',
    'user@Bücher.test' => 'deliverable',
];
const BIG = [
    // pattern => [count, expected classification]
    'user%d@bigmail.test' => [6000, 'deliverable'],
    'user%d@second-big.test' => [1500, 'deliverable'],
    'user%d@accept-all.test' => [1000, 'risky'],
    'reject-550-%d@bigmail.test' => [500, 'undeliverable'],
    'user%d@nxdomain-big.test' => [500, 'undeliverable'],
    'bad-%d' => [300, 'undeliverable'],
    'user%d@nullmx.test' => [200, 'undeliverable'],
];

$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) {
        $failures[] = $what;
    }
};
$api = static function (string $method, string $uri, string $key, ?array $body = null, array $headers = []) use ($kernel): array {
    $server = ['REMOTE_ADDR' => '10.77.0.1', 'HTTP_AUTHORIZATION' => 'Bearer '.$key, 'CONTENT_TYPE' => 'application/json'];
    foreach ($headers as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }
    $request = Request::create($uri, $method, [], [], [], $server, null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return [$response->getStatusCode(), json_decode((string) $response->getContent(), false), $response];
};
$actor = AuditActor::system('phase3-e2e');

[$command, $planFile] = [$argv[1] ?? '', $argv[2] ?? ''];
if ('prepare' === $command) {
    /** @var AccountAdministration $accounts */
    $accounts = $container->get(AccountAdministration::class);
    /** @var ApiKeyManager $keys */
    $keys = $container->get(ApiKeyManager::class);
    $active = $accounts->createClient('Phase 3 e2e', 'e2e@example.test', 'test', ClientStatus::Active, $actor);
    [, $activeKey] = $keys->create($active, 'e2e', $actor);
    $soon = $accounts->createClient('Phase 3 e2e suspended', 's@example.test', 'test', ClientStatus::Active, $actor);
    [, $soonKey] = $keys->create($soon, 'e2e', $actor);
    Db::app()->executeStatement("INSERT INTO disposable_domains (domain, source) VALUES ('tempmail.test', 'phase3-e2e') ON CONFLICT DO NOTHING");

    $mixedInput = [];
    $i = 0;
    foreach (array_keys(MIXED) as $address) {
        $mixedInput[] = ['address' => $address, 'external_address_reference' => 'mixed-'.$i++];
    }
    [$s, $mixed] = $api('POST', '/v1/validation-jobs', $activeKey, ['external_reference' => 'e2e-mixed', 'addresses' => $mixedInput], ['Idempotency-Key' => 'e2e-mixed-'.bin2hex(random_bytes(6))]);
    $check(202 === $s, "mixed job creation returned $s");

    $big = [];
    foreach (BIG as $pattern => [$count]) {
        for ($n = 0; $n < $count; ++$n) {
            $big[] = sprintf($pattern, $n);
        }
    }
    mt_srand(20261003);
    for ($n = count($big) - 1; $n > 0; --$n) {  // deterministic shuffle: mixed domains in every chunk
        $j = mt_rand(0, $n);
        [$big[$n], $big[$j]] = [$big[$j], $big[$n]];
    }
    [$s, $bigJob] = $api('POST', '/v1/validation-jobs', $activeKey, ['external_reference' => 'e2e-10000', 'addresses' => array_map(fn ($a) => ['address' => $a], $big)], ['Idempotency-Key' => 'e2e-big-'.bin2hex(random_bytes(6))]);
    $check(202 === $s && 10000 === $bigJob->total_addresses, "10,000-address job creation returned $s");

    [$s, $suspendedJob] = $api('POST', '/v1/validation-jobs', $soonKey, ['addresses' => [['address' => 'a@example.test'], ['address' => 'b@example.test']]], ['Idempotency-Key' => 'e2e-susp-'.bin2hex(random_bytes(6))]);
    $check(202 === $s, "suspended-client job creation returned $s");
    // The kernel resets its services between requests: reload the client in the current entity manager.
    $fresh = $container->get('doctrine')->getManager()->find(App\Entity\Client::class, $soon->getId());
    $container->get(AccountAdministration::class)->setClientStatus($fresh, ClientStatus::Suspended, $actor);
    [$s] = $api('POST', '/v1/validation-jobs', $soonKey, ['addresses' => [['address' => 'c@example.test']]], ['Idempotency-Key' => 'e2e-susp2-'.bin2hex(random_bytes(6))]);
    $check(403 === $s, "D-31: a suspended client's new job must be 403, got $s");

    file_put_contents($planFile, json_encode(['active_key' => $activeKey, 'suspended_key' => $soonKey, 'mixed_job' => $mixed->id,
        'big_job' => $bigJob->id, 'suspended_job' => $suspendedJob->id], JSON_PRETTY_PRINT));
    echo json_encode(['prepared' => true, 'mixed_job' => $mixed->id, 'big_job' => $bigJob->id, 'failures' => $failures]), "\n";
    exit([] === $failures ? 0 : 1);
}

if ('verify' !== $command) {
    fwrite(STDERR, "usage: e2e.php prepare|verify PLAN [STATS]\n");
    exit(2);
}
$plan = json_decode((string) file_get_contents($planFile), true, 512, JSON_THROW_ON_ERROR);
$stats = json_decode((string) file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);
/** @var OpenApiContract $contract */
$contract = $container->get(OpenApiContract::class);
$conforms = static function ($body, string $path, int $status) use ($contract): bool {
    return [] === $contract->validate($body, $contract->responseSchemaPointer($path, 'get', $status));
};
$addresses = static function (string $job, string $key) use ($api, $conforms, $check): array {
    $all = [];
    $cursor = null;
    do {
        [$s, $page] = $api('GET', "/v1/validation-jobs/$job/addresses?limit=1000".($cursor ? '&cursor='.$cursor : ''), $key);
        $check(200 === $s && $conforms($page, '/validation-jobs/{id}/addresses', 200), 'address page conforms to the OpenAPI contract');
        array_push($all, ...$page->data);
        $cursor = $page->pagination->next_cursor;
    } while (null !== $cursor);

    return $all;
};
$owner = Db::owner();
$report = [];

// Mixed scenario job.
[$s, $job] = $api('GET', '/v1/validation-jobs/'.$plan['mixed_job'], $plan['active_key']);
$check(200 === $s && $conforms($job, '/validation-jobs/{id}', 200), 'mixed job read conforms to the contract');
$check('completed' === $job->status && null !== $job->completed_at, "mixed job status {$job->status}");
$check(count(MIXED) === $job->processed_count && count(MIXED) === array_sum((array) $job->classification_counts), 'mixed job counts');
$rows = $addresses($plan['mixed_job'], $plan['active_key']);
$check(array_keys(MIXED) === array_map(fn ($r) => $r->original_address, $rows), 'original addresses unchanged and in submission order');
foreach ($rows as $i => $r) {
    $expected = MIXED[$r->original_address];
    $check($expected === $r->overall_classification, "mixed: {$r->original_address} => {$r->overall_classification} (expected $expected)");
    $check('mixed-'.$i === $r->external_address_reference, 'external_address_reference preserved');
    $check(null !== $r->checked_at && null !== $r->confidence && null !== $r->syntax_status && null !== $r->domain_status && null !== $r->smtp_status, "all result fields set for {$r->original_address}");
    $report['mixed'][$r->original_address] = [$r->overall_classification, $r->confidence, $r->diagnostic_code, $r->domain_status, $r->smtp_status];
}
$byAddress = array_column(array_map(fn ($r) => (array) $r, $rows), null, 'original_address');
$check('Someone.Else@example.test' === $byAddress[' Someone.Else@EXAMPLE.TEST ']['normalized_address'], 'D-32 normalised form stored');
$check('user@xn--bcher-kva.test' === $byAddress['user@Bücher.test']['normalized_address'], 'IDN normalised form stored');
$check('x@gmail.com' === $byAddress['x@gmial.com']['suggested_address'] && 'transposition' === $byAddress['x@gmial.com']['suggestion_reason_code'], 'typo suggestion exposed');
$check(true === $byAddress['info@example.test']['is_role'] && true === $byAddress['x@tempmail.test']['is_disposable'] && true === $byAddress['x@accept-all.test']['is_catch_all_or_accept_all'], 'risk flags exposed');

// 10,000-address job.
[$s, $big] = $api('GET', '/v1/validation-jobs/'.$plan['big_job'], $plan['active_key']);
$expectedCounts = ['deliverable' => 0, 'probably_deliverable' => 0, 'undeliverable' => 0, 'temporarily_unverifiable' => 0, 'unknown' => 0, 'risky' => 0];
foreach (BIG as [$count, $class]) {
    $expectedCounts[$class] += $count;
}
$check('completed' === $big->status && 10000 === $big->processed_count, "big job {$big->status} {$big->processed_count}");
$check($expectedCounts === (array) $big->classification_counts, 'big job classification counts are exact: '.json_encode($big->classification_counts));
$report['big_job'] = ['status' => $big->status, 'processed_count' => $big->processed_count, 'classification_counts' => $big->classification_counts];
$bigRows = $addresses($plan['big_job'], $plan['active_key']);
$check(10000 === count($bigRows) && 10000 === count(array_filter($bigRows, fn ($r) => null !== $r->overall_classification)), 'all 10,000 addresses final via the API');

// Database-level guarantees.
foreach (['mixed_job' => count(MIXED), 'big_job' => 10000] as $name => $total) {
    $usage = (int) $owner->fetchOne('SELECT coalesce(sum(quantity), 0) FROM usage_records WHERE reference_id = ? AND usage_type = ?', [$plan[$name], 'validation_address']);
    $events = $owner->fetchFirstColumn('SELECT event_type FROM webhook_events WHERE subject_id = ?', [$plan[$name]]);
    $done = (int) $owner->fetchOne("SELECT count(*) FROM validation_addresses WHERE job_id = ? AND processing_state = 'done'", [$plan[$name]]);
    $check($total === $usage, "$name usage quantity $usage (expected $total)");
    $check(['validation.completed'] === $events, "$name outbox events ".json_encode($events));
    $check($total === $done, "$name done rows $done");
    $report['db'][$name] = ['usage_quantity' => $usage, 'usage_rows' => (int) $owner->fetchOne('SELECT count(*) FROM usage_records WHERE reference_id = ?', [$plan[$name]]),
        'outbox' => $events, 'done' => $done, 'max_attempt_count' => (int) $owner->fetchOne('SELECT max(attempt_count) FROM validation_addresses WHERE job_id = ?', [$plan[$name]]),
        'evidence_rows' => (int) $owner->fetchOne('SELECT count(*) FROM validation_evidence e JOIN validation_addresses a ON a.id = e.validation_address_id WHERE a.job_id = ?', [$plan[$name]])];
}
$check($report['db']['big_job']['max_attempt_count'] >= 2, 'some addresses were reclaimed after the simulated crash');

// Suspended client: never claimed, still readable.
$susp = $owner->fetchAllAssociative('SELECT DISTINCT processing_state, attempt_count FROM validation_addresses WHERE job_id = ?', [$plan['suspended_job']]);
$check([['processing_state' => 'pending', 'attempt_count' => 0]] === $susp, 'suspended client work untouched: '.json_encode($susp));
[$s, $sj] = $api('GET', '/v1/validation-jobs/'.$plan['suspended_job'], $plan['suspended_key']);
$check(200 === $s && 'queued' === $sj->status, 'a suspended client still reads its job');

// Worker statistics of the final (post-crash) run.
$check(0 === $stats['smtp_data_commands'] && !in_array('DATA', $stats['smtp_commands'], true), 'the validator never sent DATA');
$check($stats['max_global'] <= $stats['limit_global'] && $stats['max_per_domain'] <= $stats['limit_per_domain'] && $stats['max_per_mx'] <= $stats['limit_per_mx'], 'concurrency limits held');
$check($stats['max_claim_size'] <= 250 && $stats['max_in_flight'] <= 500, 'chunking bounded the work in flight');
$report['worker_stats'] = $stats;
$report['failures'] = $failures;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
exit([] === $failures ? 0 : 1);
