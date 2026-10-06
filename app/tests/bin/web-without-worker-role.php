<?php

// Helper for tests/Integration/WebProcessIsolationTest.php: the web process as the
// symfony-app container runs it, i.e. without the webhook worker's database
// credentials. Handles a few anonymous and API requests and reports whether the
// worker-only connection was ever built.
// Usage: php tests/bin/web-without-worker-role.php <api key>
declare(strict_types=1);

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$kernel = new Kernel('test', false);
$kernel->boot();
$statuses = [];
foreach ([['GET', '/dashboard/login', []], ['POST', '/dashboard/login', ['email' => 'nobody@example.test']], ['GET', '/healthz', []],
    ['GET', '/dashboard', []], ['GET', '/v1/send-jobs/00000000-0000-7000-8000-000000000000', ['_api' => true]]] as [$method, $uri, $params]) {
    $server = ['REMOTE_ADDR' => '10.89.20.9', 'HTTP_HOST' => 'localhost', 'HTTPS' => 'on'];
    if (isset($params['_api'])) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$argv[1];
        $params = [];
    }
    $request = Request::create($uri, $method, $params, [], [], $server);
    $response = $kernel->handle($request);
    $statuses["$method $uri"] = $response->getStatusCode();
    $kernel->terminate($request, $response);
}
$container = $kernel->getContainer();
echo json_encode(['statuses' => $statuses,
    'webhook_connection_built' => $container->initialized('doctrine.dbal.webhook_connection')]), "\n";
