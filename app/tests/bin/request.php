<?php

// Concurrency helper for tests/Integration/IdempotencyTest.php and QuotaTest.php: boots its own test
// kernel in a separate process (own PHP process, own database connection),
// waits for a shared start time, sends one request and prints status + body.
// Usage: php tests/bin/request.php <spec.json> <start-unix-microtime>
declare(strict_types=1);

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$spec = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$kernel = new Kernel('test', false);
$kernel->boot();
// 'unique' gives every process its own key (concurrent distinct requests, e.g. the Phase 9 quota test).
$idempotencyKey = 'unique' === $spec['idempotency_key'] ? bin2hex(random_bytes(16)) : $spec['idempotency_key'];
$server = ['REMOTE_ADDR' => $spec['ip'], 'HTTP_AUTHORIZATION' => 'Bearer '.$spec['key'], 'CONTENT_TYPE' => 'application/json',
    'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey];
$request = Request::create($spec['uri'], $spec['method'], [], [], [], $server, $spec['body']);

while (microtime(true) < (float) $argv[2]) {
    usleep(500);
}
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'replayed' => $response->headers->get('Idempotent-Replayed'),
    'body' => json_decode((string) $response->getContent(), true)]), "\n";
$kernel->terminate($request, $response);
