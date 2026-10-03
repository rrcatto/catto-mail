<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;

final class HealthAndAuditTest extends ApiTestCase
{
    public function testHealthEndpointNeedsNoCredentialsAndRevealsNothing(): void
    {
        $this->browser->request('GET', '/healthz');
        $r = $this->browser->getResponse();
        self::assertSame(200, $r->getStatusCode());
        self::assertSame(['status' => 'ok', 'component' => 'symfony-app (php-fpm)', 'sapi' => \PHP_SAPI], self::json($r));
    }

    public function testAuditRecordsCarryNoSecrets(): void
    {
        [$client, $raw] = $this->newApiClient();
        $row = Db::owner()->fetchAssociative("SELECT * FROM audit_log WHERE action = 'api_key.created' AND detail_json->>'client_id' = ?", [$client->getId()->toRfc4122()]);
        self::assertSame('system', $row['actor_type']);
        self::assertSame('api_key', $row['target_type']);
        $detail = json_decode($row['detail_json'], true);
        self::assertSame(substr($raw, 0, 12), $detail['key_prefix']);
        self::assertStringNotContainsString(substr($raw, 12), $row['detail_json']);
    }

    public function testApiReadsAreNotAudited(): void
    {
        [$client, $raw] = $this->newApiClient();
        $before = (int) Db::owner()->fetchOne('SELECT count(*) FROM audit_log');
        $this->api('GET', '/v1/send-jobs/01999999-0000-7000-8000-000000000000', $raw);
        self::assertSame($before, (int) Db::owner()->fetchOne('SELECT count(*) FROM audit_log'), 'the audit log is not an access log');
    }
}
