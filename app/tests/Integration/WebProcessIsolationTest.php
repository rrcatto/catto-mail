<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ApiTestCase;

/**
 * The web process never needs the webhook worker's database role (least privilege,
 * schema.md §6): the symfony-app container has no APP_WEBHOOK_DB_* variables, so
 * building the worker-only connection or entity manager there is a 500. Regression
 * for the Phase 7 bug found by phase6-e2e: Doctrine's entity argument resolver built
 * every entity manager on /dashboard/login.
 */
final class WebProcessIsolationTest extends ApiTestCase
{
    public function testWebRequestsWorkWithoutTheWebhookWorkerCredentials(): void
    {
        [, $key] = $this->newApiClient();
        $env = getenv();
        unset($env['APP_WEBHOOK_DB_USER'], $env['APP_WEBHOOK_DB_PASSWORD'], $env['APP_WEBHOOK_DB_PASSWORD_FILE']);
        $proc = proc_open(['php', \dirname(__DIR__).'/bin/web-without-worker-role.php', $key], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($proc), $err);
        $result = json_decode(trim((string) $out), true, 512, \JSON_THROW_ON_ERROR);
        foreach ($result['statuses'] as $request => $status) {
            self::assertLessThan(500, $status, $request);
        }
        self::assertSame(200, $result['statuses']['GET /dashboard/login']);
        self::assertSame(404, $result['statuses']['GET /v1/send-jobs/00000000-0000-7000-8000-000000000000'], 'authenticated API request');
        // The invariant is that the worker credentials are never resolved: the connection is
        // never built. (Doctrine may hold an uninitialised lazy proxy of the worker's entity
        // manager; that resolves nothing.)
        self::assertFalse($result['webhook_connection_built'], 'the web process never builds the worker connection');
    }
}
