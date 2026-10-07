<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Bootstrap/administration lives in console commands, never in undocumented /v1 endpoints. */
final class ConsoleCommandTest extends ApiTestCase
{
    private function console(string $name, array $input, ?string $stdin = null): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find($name));
        if (null !== $stdin) {
            $tester->setInputs([$stdin]);
        }
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    /** @return array<string, string> "key: value" lines */
    private static function fields(CommandTester $t): array
    {
        preg_match_all('/^\s*([a-z_]+): (\S.*)$/m', $t->getDisplay(), $m);

        return array_combine($m[1], array_map('trim', $m[2]));
    }

    public function testDevBootstrapCreatesAWorkingClientAndShowsTheKeyOnce(): void
    {
        $email = 'operator.'.bin2hex(random_bytes(4)).'@smarthost-dev.test';
        $t = $this->console('smarthost:dev:bootstrap', ['--operator-email' => $email]);
        self::assertSame(0, $t->getStatusCode(), $t->getDisplay());
        $f = self::fields($t);
        self::assertMatchesRegularExpression('/^shk_/', $f['api_key']);
        self::assertSame($email, $f['operator_email']);
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM api_keys WHERE key_prefix = ? AND key_hash <> ?', [substr($f['api_key'], 0, 12), hash('sha256', $f['api_key'])]));
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM api_keys WHERE key_hash = ?', [hash('sha256', $f['api_key'])]));

        // The key works end to end and the development domain is usable.
        $job = self::json($this->api('POST', '/v1/send-jobs', $f['api_key'], ['external_reference' => 'dev', 'message_class' => 'transactional',
            'sender_identity' => ['email' => 'dev@smarthost-dev.test']], ['Idempotency-Key' => self::key()]));
        self::assertSame(201, $this->addBatch($f['api_key'], $job['id'], self::recipients(0, 1))->getStatusCode());
        self::assertSame('queued', self::json($this->api('POST', "/v1/send-jobs/{$job['id']}/submit", $f['api_key']))['status']);

        // Re-running reuses the client and domain and issues a new key.
        $again = self::fields($this->console('smarthost:dev:bootstrap', []));
        self::assertSame($f['client_id'], $again['client_id']);
        self::assertNotSame($f['api_key'], $again['api_key']);

        // Phase 9: repeated runs never hit the key limit; the oldest bootstrap keys are revoked (audited), the newest stay usable.
        for ($i = 0; $i < 11; ++$i) {
            static::bootKernel();
            $last = $this->console('smarthost:dev:bootstrap', []);
            self::assertSame(0, $last->getStatusCode(), $last->getDisplay());
        }
        $usable = 'SELECT count(*) FROM api_keys WHERE client_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now())';
        self::assertSame(10, (int) Db::owner()->fetchOne($usable, [$f['client_id']]), 'APP_CLIENT_API_KEY_LIMIT in the test pod');
        self::assertNotNull(Db::owner()->fetchOne('SELECT revoked_at FROM api_keys WHERE key_hash = ?', [hash('sha256', $f['api_key'])]), 'the oldest key was revoked');
        self::assertSame(200, $this->api('GET', '/v1/send-jobs/'.$job['id'], self::fields($last)['api_key'])->getStatusCode());
    }

    public function testDevBootstrapIsRefusedInProduction(): void
    {
        $this->withEnv(['SMARTHOST_ENV' => 'production'], function (): void {
            static::bootKernel();
            $t = $this->console('smarthost:dev:bootstrap', []);
            self::assertSame(1, $t->getStatusCode());
            self::assertStringContainsString('only with SMARTHOST_ENV=development or test', preg_replace('/\s+/', ' ', $t->getDisplay()));
        });
    }

    public function testAdministrationCommands(): void
    {
        static::bootKernel();
        $client = self::fields($this->console('smarthost:client:create', ['--company' => 'Console Co', '--contact-email' => 'c@example.test', '--status' => 'active']))['client_id'];
        $key = self::fields($this->console('smarthost:api-key:create', ['client-id' => $client, '--name' => 'ci']));
        self::assertSame(404, $this->api('GET', '/v1/send-jobs/01999999-0000-7000-8000-000000000000', $key['api_key'])->getStatusCode());
        static::bootKernel();
        self::assertSame(0, $this->console('smarthost:api-key:revoke', ['api-key-id' => $key['api_key_id']])->getStatusCode());
        self::assertSame(401, $this->api('GET', '/v1/send-jobs/01999999-0000-7000-8000-000000000000', $key['api_key'])->getStatusCode());
        static::bootKernel();
        $list = preg_replace('/\s+/', ' ', $this->console('smarthost:api-key:list', ['client-id' => $client])->getDisplay());
        self::assertMatchesRegularExpression('/'.$key['api_key_id'].' \S+ ci .* revoked/', $list);
        self::assertStringNotContainsString($key['api_key'], $list, 'never the secret');

        static::bootKernel();
        $domain = self::fields($this->console('smarthost:domain:add', ['client-id' => $client, 'domain' => 'console.example']));
        self::assertSame('_smarthost-verification.console.example', $domain['txt_name']);
        $this->container()->get(\App\Domain\Dns\TxtResolver::class)->set($domain['txt_name'], [$domain['txt_value']]);
        $verify = $this->console('smarthost:domain:verify', ['client-id' => $client, 'domain' => 'console.example']);
        self::assertSame(0, $verify->getStatusCode(), $verify->getDisplay());
        self::assertStringContainsString('status: verified', $verify->getDisplay());
        self::assertSame(0, $this->console('smarthost:domain:dkim', ['client-id' => $client, 'domain' => 'console.example', 'dkim-status' => 'active', '--selector' => 's1'])->getStatusCode());
        self::assertSame(1, $this->console('smarthost:domain:dkim', ['client-id' => $client, 'domain' => 'console.example', 'dkim-status' => 'bogus'])->getStatusCode());

        $email = 'console.'.bin2hex(random_bytes(4)).'@example.test';
        self::assertSame(0, $this->console('smarthost:user:create', ['email' => $email])->getStatusCode());
        self::assertNotNull(Db::owner()->fetchOne('SELECT id FROM users WHERE lower(email) = lower(?)', [$email]));
        self::assertSame(1, $this->console('smarthost:user:create', ['email' => strtoupper($email)])->getStatusCode(), 'duplicate login email');
        self::assertSame(0, $this->console('smarthost:membership:set', ['email' => $email, 'client-id' => $client, 'role' => 'admin'])->getStatusCode());
        self::assertSame(0, $this->console('smarthost:user:role', ['email' => $email, 'role' => 'operator', 'action' => 'grant'])->getStatusCode());
        self::assertSame(['OPERATOR'], Db::owner()->fetchFirstColumn('SELECT r.role_key FROM user_roles ur JOIN roles r ON r.id = ur.role_id JOIN users u ON u.id = ur.user_id WHERE lower(u.email) = lower(?)', [$email]));
        self::assertSame(1, $this->console('smarthost:user:role', ['email' => $email, 'role' => 'NOPE', 'action' => 'grant'])->getStatusCode());
        // Phase 9: a status change names the operator (with the transition's permission) and a reason.
        self::assertSame(1, $this->console('smarthost:client:set-status', ['client-id' => $client, 'status' => 'suspended'])->getStatusCode(), 'operator required');
        self::assertSame(1, $this->console('smarthost:client:set-status', ['client-id' => $client, 'status' => 'suspended', '--operator' => $email])->getStatusCode(), 'reason required');
        self::assertSame(0, $this->console('smarthost:client:set-status', ['client-id' => $client, 'status' => 'suspended', '--operator' => $email,
            '--note' => 'console test'])->getStatusCode());
        self::assertSame(1, $this->console('smarthost:client:set-status', ['client-id' => $client, 'status' => 'pending_approval', '--operator' => $email,
            '--note' => 'console test'])->getStatusCode(), 'nothing returns to pending_approval');
        $suspended = Db::owner()->fetchAssociative("SELECT actor_type, detail_json->>'note' AS note FROM audit_log WHERE action = 'client.suspended' AND target_id = ?", [$client]);
        self::assertSame(['actor_type' => 'user', 'note' => 'console test'], $suspended);
        self::assertSame(0, $this->console('smarthost:user:set-status', ['email' => $email, 'status' => 'disabled'])->getStatusCode());

        $hook = self::fields($this->console('smarthost:webhook:add', ['client-id' => $client, 'url' => 'https://hooks.example/c', '--event' => ['send.completed']]));
        self::assertMatchesRegularExpression('/^whsec_/', $hook['signing_secret']);
        $rot = self::fields($this->console('smarthost:webhook:rotate-secret', ['webhook-endpoint-id' => $hook['webhook_endpoint_id']]));
        self::assertNotSame($hook['signing_secret'], $rot['signing_secret']);
        self::assertSame(0, $this->console('smarthost:webhook:set-status', ['webhook-endpoint-id' => $hook['webhook_endpoint_id'], 'status' => 'disabled'])->getStatusCode());

        $actions = Db::owner()->fetchFirstColumn("SELECT DISTINCT action FROM audit_log WHERE actor_type = 'system' AND actor_id LIKE 'console:%'");
        foreach (['client.created', 'api_key.created', 'api_key.revoked', 'sending_domain.created', 'sending_domain.verified', 'sending_domain.dkim_changed',
            'user.created', 'client_membership.created', 'user.role_granted', 'user.disabled', 'webhook_endpoint.created',
            'webhook_endpoint.secret_rotated', 'webhook_endpoint.disabled'] as $action) {
            self::assertContains($action, $actions);
        }
    }

    public function testScheduledDomainRecheck(): void
    {
        static::bootKernel();
        $t = $this->console('smarthost:domain:verify', ['--due' => true]);
        self::assertSame(0, $t->getStatusCode());
        self::assertMatchesRegularExpression('/checked: \d+ verified: \d+/', $t->getDisplay());
    }
}
