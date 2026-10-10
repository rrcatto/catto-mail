<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\DomainRuleViolation;
use App\Enum\SystemRequestAction;
use App\System\AppliedSettings;
use App\System\SettingCatalog;
use App\System\SettingOverrides;
use App\System\SystemRequests;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * System › Settings (owner decision): settings changed in the dashboard take precedence over
 * infra/.env without changing it, need a reason, are audited with the old and new value, are
 * checked with the catalogue's rules, and are applied through the host agent (settings.apply).
 */
final class SettingsTest extends DashboardTestCase
{
    private const PAGE = '/dashboard/operator/system/settings';

    protected function setUp(): void
    {
        parent::setUp();
        Db::owner()->executeStatement('DELETE FROM setting_overrides');
        Db::owner()->executeStatement("UPDATE system_requests SET status = 'cancelled', finished_at = now(), started_at = COALESCE(started_at, now()) WHERE status IN ('pending', 'running')");
    }

    public function testTheCatalogueRules(): void
    {
        $c = $this->container()->get(SettingCatalog::class);
        self::assertTrue($c->has('SMARTHOST_TIMEZONE'));
        self::assertFalse($c->has('APP_SECRET'), 'secrets are never changeable in the dashboard');
        self::assertFalse($c->has('SMARTHOST_LIVE_DELIVERY_ENABLED'), 'the live-delivery switch keeps its own audited control');
        self::assertNull($c->error('SMARTHOST_TIMEZONE', 'Africa/Johannesburg', 'test'));
        self::assertNotNull($c->error('SMARTHOST_TIMEZONE', '+02:00', 'test'), 'an offset is not a zone');
        self::assertNotNull($c->error('SMARTHOST_TIMEZONE', 'SAST', 'test'), 'an abbreviation is not a zone');
        self::assertNull($c->error('APP_LOGIN_LINK_TTL_SECONDS', '600', 'test'));
        self::assertNotNull($c->error('APP_LOGIN_LINK_TTL_SECONDS', '5', 'test'), 'below the minimum');
        self::assertNotNull($c->error('APP_LOGIN_LINK_TTL_SECONDS', '10.5', 'test'), 'not a whole number');
        self::assertNull($c->error('APP_REPUTATION_COMPLAINT_WARNING_PERCENT', '0.25', 'test'));
        self::assertNull($c->error('DELIVERY_DSN_RET', 'FULL', 'test'));
        self::assertNotNull($c->error('DELIVERY_DSN_RET', 'ALL', 'test'));
        self::assertNull($c->error('APP_RETENTION_TRACKING_DAYS', '', 'test'), 'empty is a value of its own here');
        self::assertNotNull($c->error('BACKUP_KEEP', '7', 'test'), 'backups are production only');
        self::assertNull($c->error('BACKUP_KEEP', '7', 'production'));
        self::assertArrayNotHasKey('Backups', $c->grouped('test'));
    }

    public function testChangesAreValidatedAuditedAndCleared(): void
    {
        $admin = $this->reload($this->newUser(false, null, roleKey: 'ADMIN'));
        $o = $this->container()->get(SettingOverrides::class);
        try {
            $o->change(['APP_LOGIN_LINK_TTL_SECONDS' => '600'], '', $admin, 'test');
            self::fail('a reason is required');
        } catch (DomainRuleViolation) {
        }
        try {
            $o->change(['APP_LOGIN_LINK_TTL_SECONDS' => '5'], 'shorter links', $admin, 'test');
            self::fail('an out-of-range value is refused');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('APP_LOGIN_LINK_TTL_SECONDS', $e->getMessage());
        }
        self::assertSame(['APP_LOGIN_LINK_TTL_SECONDS', 'SMARTHOST_TIMEZONE'],
            $o->change(['APP_LOGIN_LINK_TTL_SECONDS' => '600', 'SMARTHOST_TIMEZONE' => 'Europe/London'], 'testing overrides', $admin, 'test'));
        self::assertSame(['APP_LOGIN_LINK_TTL_SECONDS' => '600', 'SMARTHOST_TIMEZONE' => 'Europe/London'], $o->values());
        self::assertSame([], $o->change(['APP_LOGIN_LINK_TTL_SECONDS' => '600'], 'no change', $admin, 'test'), 'an unchanged value is not rewritten');
        self::assertSame(['SMARTHOST_TIMEZONE'], $o->change(['SMARTHOST_TIMEZONE' => ''], 'back to the file', $admin, 'test'), 'empty clears the override');
        self::assertSame(['APP_LOGIN_LINK_TTL_SECONDS' => '600'], $o->values());
        $audit = Db::owner()->fetchAllAssociative("SELECT action, target_id, detail_json FROM audit_log WHERE target_type = 'setting' ORDER BY occurred_at, id");
        self::assertSame(['setting.override.set', 'setting.override.set', 'setting.override.cleared'], array_column($audit, 'action'));
        $cleared = json_decode((string) $audit[2]['detail_json'], true);
        self::assertSame('SMARTHOST_TIMEZONE', $audit[2]['target_id']);
        self::assertSame('Europe/London', $cleared['old'] ?? null);
        self::assertTrue(\array_key_exists('new', $cleared) && null === $cleared['new'], 'cleared: no new value');
        self::assertSame('back to the file', $cleared['reason'] ?? null);
    }

    public function testThePageAndWhoMayChangeAndApply(): void
    {
        $admin = $this->newUser(false, null, roleKey: 'ADMIN');
        $this->signIn($admin);
        $page = $this->page(self::PAGE);
        self::assertSame(200, $page->getStatusCode());
        $text = self::text($page);
        self::assertStringContainsString('takes precedence over the configuration file (infra/.env) without changing it', $text);
        self::assertStringContainsString('SMARTHOST_TIMEZONE', $text);
        self::assertStringNotContainsString('BACKUP_KEEP', $text, 'production-only settings are not offered in development');

        $response = $this->submit(self::PAGE, self::PAGE, ['group' => 'Dashboard sign-in', 'value' => ['APP_LOGIN_LINK_TTL_SECONDS' => '1200'], 'reason' => 'longer links for the test']);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('1200', Db::owner()->fetchOne("SELECT value FROM setting_overrides WHERE name = 'APP_LOGIN_LINK_TTL_SECONDS'"));
        $this->submit(self::PAGE, self::PAGE, ['group' => 'Dashboard sign-in', 'value' => ['APP_LOGIN_LINK_TTL_SECONDS' => '5'], 'reason' => 'too short']);
        self::assertSame('1200', Db::owner()->fetchOne("SELECT value FROM setting_overrides WHERE name = 'APP_LOGIN_LINK_TTL_SECONDS'"), 'a refused value changes nothing');
        $this->submit(self::PAGE, self::PAGE, ['group' => 'Dashboard sign-in', 'value' => ['APP_SECRET' => 'x', 'APP_LOGIN_LINK_TTL_SECONDS' => '1200'], 'reason' => 'smuggle']);
        self::assertFalse(Db::owner()->fetchOne("SELECT 1 FROM setting_overrides WHERE name = 'APP_SECRET'"), 'only the group\'s catalogue settings are read');

        $requests = $this->container()->get(SystemRequests::class);
        $requests->request(SystemRequestAction::SettingsApply, [], $this->reload($admin));
        self::assertSame('pending', $requests->latest(SystemRequestAction::SettingsApply)['status'] ?? null);

        $operator = $this->newUser(true);
        $this->browser->getCookieJar()->clear(); // start a new session
        $this->signIn($operator);
        self::assertSame(200, $this->page(self::PAGE)->getStatusCode(), 'operators may look');
        self::assertStringNotContainsString('Reason for the change', (string) $this->page(self::PAGE)->getContent(), 'but get no form');
        $this->browser->request('POST', self::PAGE, ['group' => 'Dashboard sign-in', 'value' => ['APP_LOGIN_LINK_TTL_SECONDS' => '900'], 'reason' => 'not allowed']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode(), 'changing needs SYSTEM.SETTINGS.MANAGE');
    }

    /** Every time is in the installation's zone: PHP, the application's and the server's sessions. */
    public function testTheInstallationZoneIsUsedEverywhere(): void
    {
        self::assertSame('Africa/Johannesburg', date_default_timezone_get());
        self::assertSame('Africa/Johannesburg', $this->container()->get(\Doctrine\DBAL\Connection::class)->fetchOne('SHOW TimeZone'), 'application session');
        self::assertSame('Africa/Johannesburg', Db::owner()->fetchOne('SHOW TimeZone'), 'server default (timezone setting)');
        self::assertStringEndsWith('+02', (string) Db::owner()->fetchOne("SELECT CAST(timestamptz '2026-10-10 08:00:00+00' AS text)"));
    }

    public function testTheAppliedStateFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'settings');
        file_put_contents($file, json_encode(['rendered_at' => '2026-10-10T10:00:00+02:00', 'config' => ['APP_LOGIN_LINK_TTL_SECONDS' => '900'],
            'applied' => ['SMARTHOST_TIMEZONE' => 'Europe/London'], 'ignored' => ['APP_SECRET' => 'not a setting the dashboard may change'], 'errors' => []]));
        $state = (new AppliedSettings($file))->state();
        unlink($file);
        self::assertSame('Europe/London', $state['applied']['SMARTHOST_TIMEZONE'] ?? null);
        self::assertSame('900', $state['config']['APP_LOGIN_LINK_TTL_SECONDS'] ?? null);
        self::assertArrayHasKey('APP_SECRET', $state['ignored']);
        self::assertNull((new AppliedSettings('/nonexistent/settings.json'))->state());
    }

    public function testTheConsoleCommand(): void
    {
        $admin = $this->newUser(false, null, roleKey: 'ADMIN');
        static::bootKernel();
        $t = new CommandTester((new Application(static::$kernel))->find('smarthost:settings'));
        $t->execute(['action' => 'set', 'name' => 'DELIVERY_GLOBAL_RATE_PER_MINUTE', 'value' => '30', '--operator' => $admin->getEmail(), '--reason' => 'warm-up stage 1']);
        self::assertSame(0, $t->getStatusCode(), $t->getDisplay());
        $t->execute(['action' => 'list']);
        self::assertStringContainsString('DELIVERY_GLOBAL_RATE_PER_MINUTE=30', $t->getDisplay());
        $t->execute(['action' => 'reset', '--all' => true, '--operator' => $admin->getEmail(), '--reason' => 'back to the file']);
        self::assertSame(0, $t->getStatusCode(), $t->getDisplay());
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM setting_overrides'));
    }
}
