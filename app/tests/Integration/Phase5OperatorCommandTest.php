<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Client\AccountAdministration;
use App\Tests\Schema\SchemaFixtures;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Phase 5 operator administration (D-30, D-05): the global-opt-out capability,
 * operator suppressions and lifting, and the unmatched-DSN workflow. Every action
 * needs an enabled operator and is audited with that operator's identity.
 */
final class Phase5OperatorCommandTest extends ApiTestCase
{
    private function console(string $name, array $input): CommandTester
    {
        static::bootKernel();
        $tester = new CommandTester((new Application(static::$kernel))->find($name));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    /** @return array<string, string> */
    private static function fields(CommandTester $t): array
    {
        preg_match_all('/^\s*([a-z_]+): (\S.*)$/m', $t->getDisplay(), $m);

        return array_combine($m[1], array_map('trim', $m[2]));
    }

    private function operatorEmail(bool $operator = true): string
    {
        $email = 'op.'.bin2hex(random_bytes(4)).'@smarthost-dev.test';
        $user = $this->service(AccountAdministration::class)->createUser($email, 'Operator', self::actor());
        if ($operator) {
            $this->service(\App\Access\AccessControl::class)->grantRoleAsSystem($user, 'OPERATOR', self::actor());
        }

        return $email;
    }

    private static function audit(string $action, string $target): array|false
    {
        return Db::owner()->fetchAssociative('SELECT * FROM audit_log WHERE action = ? AND target_id = ? ORDER BY occurred_at DESC LIMIT 1', [$action, $target]);
    }

    public function testCapabilityIsOperatorControlledAndAudited(): void
    {
        $client = $this->newClient();
        $id = $client->getId()->toRfc4122();
        $nonOperator = $this->operatorEmail(false);
        self::assertSame(1, $this->console('smarthost:client:global-suppressions', ['client-id' => $id, 'setting' => 'enable', '--operator' => $nonOperator, '--note' => 'x'])->getStatusCode());
        self::assertSame(1, $this->console('smarthost:client:global-suppressions', ['client-id' => $id, 'setting' => 'enable', '--note' => 'x'])->getStatusCode(), 'operator required');
        $op = $this->operatorEmail();
        self::assertSame(1, $this->console('smarthost:client:global-suppressions', ['client-id' => $id, 'setting' => 'enable', '--operator' => $op])->getStatusCode(), 'note required');
        self::assertFalse((bool) Db::owner()->fetchOne('SELECT can_submit_global_suppressions FROM clients WHERE id = ?', [$id]));

        $t = $this->console('smarthost:client:global-suppressions', ['client-id' => $id, 'setting' => 'enable', '--operator' => $op, '--note' => 'trusted client application']);
        self::assertSame(0, $t->getStatusCode(), $t->getDisplay());
        self::assertTrue((bool) Db::owner()->fetchOne('SELECT can_submit_global_suppressions FROM clients WHERE id = ?', [$id]));
        $audit = self::audit('client.global_suppressions_enabled', $id);
        self::assertSame('user', $audit['actor_type']);
        self::assertSame(Db::owner()->fetchOne('SELECT id::text FROM users WHERE email = ?', [$op]), $audit['actor_id']);

        self::assertSame(0, $this->console('smarthost:client:global-suppressions', ['client-id' => $id, 'setting' => 'disable', '--operator' => $op, '--note' => 'no longer'])->getStatusCode());
        self::assertFalse((bool) Db::owner()->fetchOne('SELECT can_submit_global_suppressions FROM clients WHERE id = ?', [$id]));
        self::assertNotFalse(self::audit('client.global_suppressions_disabled', $id));
    }

    public function testOperatorSuppressionsAndAuditedLift(): void
    {
        $op = $this->operatorEmail();
        $address = 'Blocked'.bin2hex(random_bytes(3)).'@Example.NET';
        $normalized = explode('@', $address)[0].'@example.net';
        self::assertSame(1, $this->console('smarthost:suppression:create', ['--address' => $address, '--reason' => 'hard_bounce', '--operator' => $op, '--note' => 'n'])->getStatusCode(),
            'operators cannot forge system reasons');
        self::assertSame(1, $this->console('smarthost:suppression:create', ['--address' => $address, '--reason' => 'recipient_global_opt_out', '--operator' => $op, '--note' => 'n'])->getStatusCode());

        $block = self::fields($this->console('smarthost:suppression:create', ['--address' => $address, '--operator' => $op, '--note' => 'abuse report']));
        self::assertSame($normalized, $block['value']);
        $domain = self::fields($this->console('smarthost:suppression:create', ['--domain' => 'Spam-Trap.Example', '--reason' => 'client_abuse_block', '--operator' => $op, '--note' => 'n']));
        self::assertSame('spam-trap.example', $domain['value']);
        $hard = SchemaFixtures::id();
        Db::owner()->insert('suppressions', ['id' => $hard, 'address_or_domain' => $normalized, 'scope_type' => 'address', 'reason' => 'hard_bounce']);
        self::assertNotFalse(self::audit('suppression.operator_created', $block['suppression_id']));

        $list = $this->console('smarthost:suppression:list', ['--address' => $address, '--active' => true]);
        self::assertStringContainsString('rows: 2', $list->getDisplay());

        self::assertSame(1, $this->console('smarthost:suppression:lift', ['suppression-id' => $hard, '--operator' => $op])->getStatusCode(), 'a note is required');
        $lift = $this->console('smarthost:suppression:lift', ['suppression-id' => $hard, '--operator' => $op, '--note' => 'mailbox restored, confirmed by the recipient']);
        self::assertSame(0, $lift->getStatusCode(), $lift->getDisplay());
        self::assertSame('true', self::fields($lift)['lifted']);
        self::assertSame('1', self::fields($lift)['other_active_suppressions_of_address'], 'the operator block still applies');
        $audit = self::audit('suppression.operator_lifted', $hard);
        self::assertSame('user', $audit['actor_type']);
        self::assertStringContainsString('mailbox restored', (string) $audit['detail_json']);
        self::assertNotNull(Db::owner()->fetchOne('SELECT lifted_at FROM suppressions WHERE id = ?', [$hard]), 'lifted, not deleted');
        self::assertStringStartsWith('false', self::fields($this->console('smarthost:suppression:lift', ['suppression-id' => $hard, '--operator' => $op, '--note' => 'again']))['lifted']);
    }

    public function testUnmatchedDsnWorkflow(): void
    {
        $op = $this->operatorEmail();
        $c = Db::owner();
        $client = SchemaFixtures::client($c);
        $job = SchemaFixtures::sendJob($c, $client, SchemaFixtures::domain($c, $client));
        $message = SchemaFixtures::message($c, $job, SchemaFixtures::recipient($c, $job, SchemaFixtures::batch($c, $job)));
        $dsn = function () use ($c): string {
            $id = SchemaFixtures::id();
            $c->insert('unmatched_dsns', ['id' => $id, 'received_at' => '2026-10-04T00:00:00Z',
                'spool_ingest_key' => '1790000000.V1I'.random_int(1, 1 << 30).'M1.postfix', 'content_sha256' => str_repeat('b', 64),
                'classification' => 'hard_bounce', 'raw_message' => "Subject: Undelivered\x1b[31m\n\nbody", 'final_recipient' => 'someone@example.org']);

            return $id;
        };
        $first = $dsn();
        $second = $dsn();

        $list = $this->console('smarthost:dsn:list', []);
        self::assertStringContainsString($first, $list->getDisplay());
        $show = $this->console('smarthost:dsn:show', ['id' => $first, '--raw' => true]);
        self::assertStringContainsString('someone@example.org', $show->getDisplay());
        self::assertStringNotContainsString("\x1b", $show->getDisplay(), 'remote content cannot drive the terminal');

        // Match request: recorded, audited, Go woken; Symfony creates no event.
        $listen = \Pdo\Pgsql::connect(\sprintf('pgsql:host=%s;port=%s;dbname=%s', Db::env('SMARTHOST_DB_HOST'), Db::env('SMARTHOST_DB_PORT'), Db::databaseName()),
            Db::env('APP_DB_USER'), Db::env('APP_DB_PASSWORD'));
        $listen->exec('LISTEN smarthost_unmatched_dsn_work');
        $t = $this->console('smarthost:dsn:match', ['id' => $first, 'message-id' => $message, '--operator' => $op, '--note' => 'same recipient and time']);
        self::assertSame(0, $t->getStatusCode(), $t->getDisplay());
        $row = $c->fetchAssociative('SELECT * FROM unmatched_dsns WHERE id = ?', [$first]);
        self::assertSame('match_requested', $row['status']);
        self::assertSame($message, $row['matched_message_id']);
        self::assertSame($c->fetchOne('SELECT id::text FROM users WHERE email = ?', [$op]), $row['resolution_requested_by']);
        self::assertNull($row['resolution_event_id']);
        self::assertSame(0, (int) $c->fetchOne('SELECT count(*) FROM message_events WHERE message_id = ?', [$message]));
        $notify = $listen->getNotify(\PDO::FETCH_ASSOC, 2000);
        self::assertSame($first, $notify['payload'] ?? null);
        self::assertNotFalse(self::audit('unmatched_dsn.match_requested', $first));
        self::assertSame(1, $this->console('smarthost:dsn:match', ['id' => $first, 'message-id' => $message, '--operator' => $op])->getStatusCode(), 'only open rows');
        self::assertSame(1, $this->console('smarthost:dsn:dismiss', ['id' => $first, '--operator' => $op, '--reason' => 'x'])->getStatusCode(), 'only open rows');

        // Dismissal requires a written reason and keeps the row.
        self::assertSame(1, $this->console('smarthost:dsn:dismiss', ['id' => $second, '--operator' => $op])->getStatusCode());
        self::assertSame(1, $this->console('smarthost:dsn:match', ['id' => $second, 'message-id' => SchemaFixtures::id(), '--operator' => $op])->getStatusCode(), 'unknown message');
        self::assertSame(0, $this->console('smarthost:dsn:dismiss', ['id' => $second, '--operator' => $op, '--reason' => 'backscatter from an unrelated sender'])->getStatusCode());
        $row = $c->fetchAssociative('SELECT * FROM unmatched_dsns WHERE id = ?', [$second]);
        self::assertSame('dismissed', $row['status']);
        self::assertSame('backscatter from an unrelated sender', $row['resolution_note']);
        self::assertNotNull($row['resolved_at']);
        self::assertSame('user', self::audit('unmatched_dsn.dismissed', $second)['actor_type']);
    }
}

