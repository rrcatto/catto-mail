<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\SetupStepState;
use App\Enum\SystemCheckSource;
use App\Enum\SystemRequestAction;
use App\Help\HelpCatalog;
use App\Security\LoginLinkService;
use App\System\ApplicationDiagnostics;
use App\System\DeliveryControl;
use App\System\Redactor;
use App\System\SetupWizard;
use App\System\SystemChecks;
use App\System\SystemRequests;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Operator self-service (specification 2.11): application diagnostics and the meaningful
 * history, the host-agent protocol (claim, finish, report), redaction of secrets, the
 * emergency stop, the setup wizard and its first-login redirect, the help pages, the
 * host-printed bootstrap sign-in link, and who may do what.
 */
final class SystemTest extends DashboardTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $o = Db::owner();
        $o->executeStatement("UPDATE system_requests SET status = 'cancelled', finished_at = now(), started_at = COALESCE(started_at, now()) WHERE status IN ('pending', 'running')");
        $o->executeStatement('DELETE FROM delivery_controls');
    }

    private function console(string $name, array $input, ?string $stdin = null): CommandTester
    {
        static::bootKernel();
        $t = new CommandTester((new Application(static::$kernel))->find($name));
        if (null !== $stdin) {
            $t->setInputs([$stdin]);
        }
        $t->execute($input, ['interactive' => false]);

        return $t;
    }

    public function testApplicationDiagnosticsAndTheHistoryRules(): void
    {
        $results = $this->container()->get(ApplicationDiagnostics::class)->run(true);
        $by = array_column($results, null, 'key');
        foreach (['app.database', 'app.migrations', 'app.web', 'app.tracking.pixel', 'app.tracking.redirect'] as $key) {
            self::assertSame('pass', $by[$key]['result'], $key.': '.$by[$key]['summary']);
        }
        self::assertSame('info', $by['app.validator.e2e']['result'], 'the deterministic validator job was started');
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM validation_jobs WHERE external_reference = 'system-test' AND submitted_at > now() - interval '1 minute'"));

        $checks = $this->container()->get(SystemChecks::class);
        $key = 'test.history-'.bin2hex(random_bytes(3));
        $one = static fn (string $result): array => [['key' => $key, 'component' => 'host', 'title' => 'History test', 'result' => $result, 'summary' => 'x']];
        $checks->record($one('pass'), SystemCheckSource::Agent, false);   // the first result ever: a change from nothing
        $checks->record($one('pass'), SystemCheckSource::Agent, false);   // same result, same day: not kept
        $checks->record($one('fail'), SystemCheckSource::Agent, false);   // changed
        $checks->record($one('fail'), SystemCheckSource::Agent, true);    // requested
        self::assertSame(['changed', 'changed', 'requested'], Db::owner()->fetchFirstColumn(
            'SELECT run_trigger FROM system_check_runs WHERE check_key = ? ORDER BY ran_at', [$key]));
        self::assertSame('fail', $checks->get($key)['result']);
        Db::owner()->executeStatement("UPDATE system_check_runs SET ran_at = ran_at - interval '1 day' WHERE check_key = ?", [$key]);
        $checks->record($one('fail'), SystemCheckSource::Agent, false);   // unchanged, but the first of a new day
        self::assertSame('scheduled', Db::owner()->fetchOne('SELECT run_trigger FROM system_check_runs WHERE check_key = ? ORDER BY ran_at DESC LIMIT 1', [$key]));
        self::assertSame(0, $checks->record([['key' => 'BAD KEY', 'component' => 'host', 'result' => 'pass']], SystemCheckSource::Agent, false));
    }

    public function testSecretsNeverReachStoredResults(): void
    {
        // The PEM markers are assembled so that no key marker appears in the repository (phase 1 verify).
        $pem = static fn (string $edge): string => '-----'.$edge.' PRIVATE'.' KEY-----';
        $text = Redactor::text('key shk_abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG password=hunter2 /p/'.str_repeat('a', 43)
            .' /dashboard/login/verify?token='.str_repeat('b', 43).' '.$pem('BEGIN')."\nMIIE\n".$pem('END'));
        foreach (['abcdefghijklmnop', 'hunter2', str_repeat('a', 43), str_repeat('b', 43), 'MIIE'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
        self::assertSame(['password' => '[redacted]', 'nested' => ['api_key' => '[redacted]', 'ok' => 'fine']],
            Redactor::data(['password' => 'x', 'nested' => ['api_key' => 'y', 'ok' => 'fine']]));
    }

    public function testTheAgentProtocol(): void
    {
        $admin = $this->newUser(false, null, roleKey: 'ADMIN');
        $requests = $this->container()->get(SystemRequests::class);
        $id = $requests->request(SystemRequestAction::DkimGenerate, ['domain' => 'News.Example.org', 'selector' => 's2026a'], $this->reload($admin));
        try {
            $requests->request(SystemRequestAction::DkimGenerate, ['domain' => 'x; rm -rf /', 'selector' => 's'], $this->reload($admin));
            self::fail('invalid parameters');
        } catch (\App\Domain\DomainRuleViolation) {
        }
        $claim = $this->console('smarthost:system:agent', ['action' => 'claim']);
        $claimed = json_decode(trim($claim->getDisplay()), true);
        self::assertSame([$id, 'dkim.generate', ['domain' => 'news.example.org', 'selector' => 's2026a'], $admin->getEmail()],
            [$claimed[0]['id'], $claimed[0]['action'], $claimed[0]['params'], $claimed[0]['requested_by']]);
        self::assertSame('[]', trim($this->console('smarthost:system:agent', ['action' => 'claim'])->getDisplay()), 'claimed once');
        $this->console('smarthost:system:agent', ['action' => 'finish', 'request-id' => $id],
            json_encode(['summary' => 'generated; token=abcdefghijklmnopqrstuvwxyz0123', 'result' => ['secret' => 'zzz']]));
        $row = Db::owner()->fetchAssociative('SELECT status, result_summary, result_json FROM system_requests WHERE id = ?', [$id]);
        self::assertSame('succeeded', $row['status']);
        self::assertStringNotContainsString('abcdefghijklmnop', $row['result_summary']);
        self::assertStringNotContainsString('zzz', $row['result_json']);
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'system.request.dkim.generate' AND target_id = ?", [$id]));

        $report = $this->console('smarthost:system:agent', ['action' => 'report', '--requested' => true], json_encode([
            'checks' => [['key' => 'host.disk-home', 'component' => 'host', 'title' => 'Free disk space (home)', 'result' => 'pass', 'summary' => '30 GB free']],
            'state' => ['host_report' => ['os' => 'Ubuntu 26.04.1 LTS', 'containers' => ['validator' => ['state' => 'running', 'health' => 'healthy']]]],
        ]));
        self::assertStringContainsString('recorded 1 checks', $report->getDisplay());
        self::assertSame('Ubuntu 26.04.1 LTS', json_decode((string) Db::owner()->fetchOne("SELECT value_json FROM system_state WHERE state_key = 'host_report'"), true)['os']);
        self::assertNotNull(Db::owner()->fetchOne("SELECT updated_at FROM system_state WHERE state_key = 'agent_heartbeat'"));

        // A complete report retires the agent checks it no longer contains (here: a renamed host's
        // DNS check); a partial report never does, application checks are never touched, and the
        // retired check's history stays.
        $line = fn (string $key): array => ['key' => $key, 'component' => 'dns', 'title' => $key, 'result' => 'fail', 'summary' => 'no A record'];
        $this->console('smarthost:system:agent', ['action' => 'report'], json_encode(['checks' => [$line('dns.a-old-example-org'), $line('dns.a-new-example-org')]]));
        $this->console('smarthost:system:agent', ['action' => 'diagnostics']);
        $partial = $this->console('smarthost:system:agent', ['action' => 'report'], json_encode(['checks' => [$line('dns.a-new-example-org')]]));
        self::assertStringNotContainsString('retired', $partial->getDisplay());
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM system_checks WHERE check_key = 'dns.a-old-example-org'"));
        $complete = $this->console('smarthost:system:agent', ['action' => 'report'], json_encode(['checks' => [$line('dns.a-new-example-org')], 'complete' => true]));
        self::assertMatchesRegularExpression('/retired [2-9]\d* no longer reported/', $complete->getDisplay(), 'at least the old DNS check and host.disk-home');
        self::assertSame(['dns.a-new-example-org'], Db::owner()->fetchFirstColumn("SELECT check_key FROM system_checks WHERE source = 'agent'"));
        self::assertGreaterThan(5, (int) Db::owner()->fetchOne("SELECT count(*) FROM system_checks WHERE source = 'application'"));
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM system_check_runs WHERE check_key = 'dns.a-old-example-org'"));
        $empty = $this->console('smarthost:system:agent', ['action' => 'report'], json_encode(['checks' => [], 'complete' => true]));
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM system_checks WHERE source = 'agent'"), 'an empty report retires nothing');
        self::assertStringContainsString('recorded 0 checks', $empty->getDisplay());
    }

    public function testEmergencyStopFromTheDashboard(): void
    {
        $admin = $this->newUser(false, null, roleKey: 'ADMIN');
        $operator = $this->newUser(true);
        $this->signIn($operator);
        $this->browser->request('POST', '/dashboard/operator/system/stop', ['_token' => 'x', 'note' => 'no permission']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode(), 'OPERATOR lacks SYSTEM.DELIVERY.CONTROL');

        $this->browser->getCookieJar()->clear();
        $this->signIn($admin);
        Db::owner()->executeStatement("INSERT INTO setup_steps (step_key, state, updated_at) VALUES ('readiness', 'done', now()) ON CONFLICT (step_key) DO UPDATE SET state = 'done'");
        $html = self::text($this->page('/dashboard/operator/system'));
        self::assertStringContainsString('STOP SENDING EMAIL NOW', $html);
        $this->submit('/dashboard/operator/system', '/dashboard/operator/system/stop', ['note' => 'complaint spike']);
        $control = $this->container()->get(DeliveryControl::class);
        self::assertTrue($control->isStopped());
        $status = $control->status();
        self::assertTrue($status['emergency_stop']);
        self::assertContains($status['mode'], ['PAUSED', 'STOPPED'], 'PAUSED, or STOPPED when no delivery daemon reports in this test database');
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM system_requests WHERE action = 'delivery.pause' AND status = 'pending'"));
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'delivery.outbound_paused' AND actor_id = ? AND detail_json->>'via' = 'dashboard'", [$admin->getId()->toRfc4122()]));

        $this->submit('/dashboard/operator/system', '/dashboard/operator/system/resume', ['note' => 'investigated', 'confirm' => 'resume']);
        self::assertTrue($control->isStopped(), 'the typed confirmation must match');
        $this->submit('/dashboard/operator/system', '/dashboard/operator/system/resume', ['note' => 'investigated', 'confirm' => 'RESUME SENDING']);
        self::assertFalse($control->isStopped());
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM system_requests WHERE action = 'delivery.resume' AND status = 'pending'"));

        $this->submit('/dashboard/operator/system', '/dashboard/operator/system/live', ['to' => 'enable', 'note' => 'tests passed', 'confirm' => 'yes']);
        self::assertSame(0, (int) Db::owner()->fetchOne("SELECT count(*) FROM system_requests WHERE action = 'delivery.live_enable'"), 'no accidental live activation');
    }

    public function testConsoleEmergencyStopNeedsTheDeliveryPermission(): void
    {
        $operator = $this->newUser(true);
        self::assertSame(1, $this->console('smarthost:delivery:emergency-stop', ['state' => 'on', '--operator' => $operator->getEmail(), '--note' => 'test'])->getStatusCode());
        $admin = $this->newUser(false, null, roleKey: 'ADMIN');
        self::assertSame(0, $this->console('smarthost:delivery:emergency-stop', ['state' => 'on', '--operator' => $admin->getEmail(), '--note' => 'test'])->getStatusCode());
        self::assertTrue($this->container()->get(DeliveryControl::class)->isStopped());
        $this->console('smarthost:delivery:emergency-stop', ['state' => 'off', '--operator' => $admin->getEmail(), '--note' => 'test done']);
        self::assertFalse($this->container()->get(DeliveryControl::class)->isStopped());
    }

    public function testSetupWizardFirstLoginAndProgress(): void
    {
        Db::owner()->executeStatement('DELETE FROM setup_steps');
        $admin = $this->newUser(false, null, roleKey: 'ADMIN');
        $this->signIn($admin);
        $this->browser->request('GET', '/dashboard');
        self::assertStringEndsWith('/dashboard/operator/setup', (string) $this->browser->getResponse()->headers->get('Location'), 'a new installation opens the wizard');
        $index = self::text($this->page('/dashboard/operator/setup'));
        self::assertStringContainsString('Continue with step 1: Welcome', $index);
        foreach (array_keys(SetupWizard::STEPS) as $step) {
            self::assertSame(200, $this->page("/dashboard/operator/setup/$step")->getStatusCode(), $step);
        }
        $r = $this->submit('/dashboard/operator/setup/welcome', '/dashboard/operator/setup/welcome/state', ['state' => 'done']);
        self::assertStringEndsWith('/dashboard/operator/setup/host', (string) $r->headers->get('Location'));
        $this->submit('/dashboard/operator/setup/host', '/dashboard/operator/setup/host/state', ['state' => 'skipped', 'note' => 'later']);
        self::assertSame('web_identity', $this->container()->get(SetupWizard::class)->current(), 'resumes at the first step not handled');
        self::assertSame(['done', 'skipped'], Db::owner()->fetchFirstColumn("SELECT state FROM setup_steps WHERE step_key IN ('welcome', 'host') ORDER BY step_key = 'host'"));
        $this->container()->get(SetupWizard::class)->set('readiness', SetupStepState::Done, $this->reload($admin));
        $this->browser->request('GET', '/dashboard');
        self::assertStringNotContainsString('/setup', (string) $this->browser->getResponse()->headers->get('Location'), 'no redirect once complete');
        self::assertSame(200, $this->page('/dashboard/operator/setup')->getStatusCode(), 'and it stays available');
    }

    public function testHelpPagesAndPermissions(): void
    {
        $operator = $this->newUser(true);
        $this->signIn($operator);
        self::assertSame(200, $this->page('/dashboard/operator/help')->getStatusCode());
        foreach (array_keys(HelpCatalog::topics()) as $topic) {
            $r = $this->page("/dashboard/operator/help/$topic");
            self::assertSame(200, $r->getStatusCode(), $topic);
        }
        $delivery = self::text($this->page('/dashboard/operator/help/delivery-states'));
        self::assertStringContainsString('remote accepted', strtolower($delivery));
        self::assertSame(404, $this->page('/dashboard/operator/help/no-such-topic')->getStatusCode());
        self::assertSame(200, $this->page('/dashboard/operator/system')->getStatusCode(), 'OPERATOR views system health');
        self::assertSame(200, $this->page('/dashboard/operator/system/diagnostics')->getStatusCode());
        self::assertSame(403, $this->page('/dashboard/operator/setup')->getStatusCode(), 'the wizard is ADMIN-only');
        self::assertSame(403, $this->page('/dashboard/operator/batches')->getStatusCode(), 'batches are ADMIN-only');
        $this->browser->request('POST', '/dashboard/operator/system/diagnostics/run', ['_token' => 'x']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());

        $this->browser->getCookieJar()->clear();
        $client = $this->newClient();
        $this->signIn($this->newUser(false, $client, \App\Enum\ClientMembershipRole::Admin));
        foreach (['/dashboard/operator/system', '/dashboard/operator/help', '/dashboard/operator/setup', '/dashboard/operator/batches'] as $uri) {
            self::assertSame(403, $this->page($uri)->getStatusCode(), $uri);
        }
    }

    public function testBootstrapSignInLinkIsAdminOnlyShortAndSingleUse(): void
    {
        $links = $this->container()->get(LoginLinkService::class);
        $operator = $this->newUser(true);
        try {
            $links->issueHostLink($operator->getEmail());
            self::fail('an OPERATOR is not an administrator');
        } catch (\InvalidArgumentException) {
        }
        $t = $this->console('smarthost:admin:login-link', ['--minutes' => '60']);
        self::assertSame(0, $t->getStatusCode(), $t->getDisplay());
        self::assertSame(1, preg_match('#sign_in_url: https://smarthost\.localhost(/dashboard/login/verify\?token=[A-Za-z0-9_-]{43})#', $t->getDisplay(), $m));
        self::assertStringContainsString('valid_for: 15 minutes', $t->getDisplay(), 'capped at 15 minutes');
        $row = Db::owner()->fetchAssociative("SELECT extract(epoch FROM expires_at - created_at)::int AS ttl, return_path FROM auth_login_tokens ORDER BY created_at DESC LIMIT 1");
        self::assertSame([900, '/dashboard/operator/setup'], [(int) $row['ttl'], $row['return_path']]);
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'auth.host_login_link_issued' AND occurred_at > now() - interval '1 minute'"));

        $this->browser->request('GET', $m[1]);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        self::assertStringNotContainsString('/dashboard/login', (string) $this->browser->getResponse()->headers->get('Location'), 'signed in');
        $this->browser->getCookieJar()->clear();
        $this->browser->request('GET', $m[1]);
        self::assertStringContainsString('/dashboard/login', (string) $this->browser->getResponse()->headers->get('Location'), 'used once');
    }
}
