<?php

declare(strict_types=1);

namespace App\Tests\Schema;

use App\Tests\Support\Db;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the critical CHECK, UNIQUE and FOREIGN KEY constraints of the
 * migrated schema. Each case runs in a transaction that is rolled back.
 */
final class ConstraintTest extends TestCase
{
    private Connection $c;

    protected function setUp(): void
    {
        $this->c = Db::owner();
        $this->c->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->c->isTransactionActive()) {
            $this->c->rollBack();
        }
    }

    /** Runs $fn in a savepoint and returns the violated constraint name (or null). */
    private function violation(callable $fn): ?string
    {
        $this->c->executeStatement('SAVEPOINT t');
        try {
            $fn($this->c);
            $this->c->executeStatement('RELEASE SAVEPOINT t');

            return null;
        } catch (DriverException $e) {
            // 23xxx = integrity constraint violation (CHECK 23514 is not mapped to a DBAL subclass).
            if (!str_starts_with((string) $e->getSQLState(), '23')) {
                throw $e;
            }
            $this->c->executeStatement('ROLLBACK TO SAVEPOINT t');
            preg_match('/constraint "([^"]+)"/', $e->getMessage(), $m);

            return $m[1] ?? get_class($e);
        }
    }

    public function testLoginEmailIsUniqueCaseInsensitively(): void
    {
        $this->c->insert('users', ['id' => SchemaFixtures::id(), 'email' => 'Ops@Example.test']);
        self::assertSame('users_email_uq', $this->violation(fn (Connection $c) => $c->insert('users', ['id' => SchemaFixtures::id(), 'email' => 'ops@example.TEST'])));
        self::assertNull($this->violation(fn (Connection $c) => $c->insert('users', ['id' => SchemaFixtures::id(), 'email' => 'other@example.test'])));
    }

    public function testGlobalRoleAndStatusAreRestricted(): void
    {
        self::assertSame('users_global_role_check', $this->violation(fn (Connection $c) => $c->insert('users', ['id' => SchemaFixtures::id(), 'email' => 'a@x.test', 'global_role' => 'admin'])));
        self::assertSame('users_status_check', $this->violation(fn (Connection $c) => $c->insert('users', ['id' => SchemaFixtures::id(), 'email' => 'b@x.test', 'status' => 'locked'])));
    }

    public function testMembershipIsUniquePerUserAndClientAndRoleRestricted(): void
    {
        $client = SchemaFixtures::client($this->c);
        $user = SchemaFixtures::id();
        $this->c->insert('users', ['id' => $user, 'email' => 'm@x.test']);
        $this->c->insert('client_memberships', ['id' => SchemaFixtures::id(), 'user_id' => $user, 'client_id' => $client, 'role' => 'viewer']);
        self::assertSame('client_memberships_user_client_uq', $this->violation(fn (Connection $c) => $c->insert('client_memberships',
            ['id' => SchemaFixtures::id(), 'user_id' => $user, 'client_id' => $client, 'role' => 'admin'])));
        self::assertSame('client_memberships_role_check', $this->violation(fn (Connection $c) => $c->insert('client_memberships',
            ['id' => SchemaFixtures::id(), 'user_id' => $user, 'client_id' => SchemaFixtures::client($c), 'role' => 'owner'])));
    }

    public function testApiKeyHashMustBeSha256HexAndUnique(): void
    {
        $client = SchemaFixtures::client($this->c);
        $hash = hash('sha256', 'x');
        $this->c->insert('api_keys', ['id' => SchemaFixtures::id(), 'client_id' => $client, 'key_hash' => $hash, 'key_prefix' => 'shk_x']);
        self::assertSame('api_keys_key_hash_uq', $this->violation(fn (Connection $c) => $c->insert('api_keys', ['id' => SchemaFixtures::id(), 'client_id' => $client, 'key_hash' => $hash, 'key_prefix' => 'p'])));
        self::assertSame('api_keys_key_hash_check', $this->violation(fn (Connection $c) => $c->insert('api_keys', ['id' => SchemaFixtures::id(), 'client_id' => $client, 'key_hash' => 'shk_plaintext-key', 'key_prefix' => 'p'])));
    }

    public function testSendingDomainRules(): void
    {
        $client = SchemaFixtures::client($this->c);
        $base = ['client_id' => $client, 'verification_token' => str_repeat('b', 43)];
        self::assertSame('sending_domains_domain_check', $this->violation(fn (Connection $c) => $c->insert('sending_domains', $base + ['id' => SchemaFixtures::id(), 'domain' => 'Upper.example'])));
        self::assertSame('sending_domains_verification_token_check', $this->violation(fn (Connection $c) => $c->insert('sending_domains', ['id' => SchemaFixtures::id(), 'client_id' => $client, 'domain' => 'short.example', 'verification_token' => 'short'])));
        self::assertSame('sending_domains_verified_has_time', $this->violation(fn (Connection $c) => $c->insert('sending_domains', $base + ['id' => SchemaFixtures::id(), 'domain' => 'v.example', 'status' => 'verified'])));
        self::assertSame('sending_domains_dkim_has_selector', $this->violation(fn (Connection $c) => $c->insert('sending_domains', $base + ['id' => SchemaFixtures::id(), 'domain' => 'k.example', 'dkim_status' => 'active'])));
        $this->c->insert('sending_domains', $base + ['id' => SchemaFixtures::id(), 'domain' => 'dup.example']);
        self::assertSame('sending_domains_client_domain_uq', $this->violation(fn (Connection $c) => $c->insert('sending_domains', $base + ['id' => SchemaFixtures::id(), 'domain' => 'dup.example'])));
        // The same domain may be registered by another client (verification proves control).
        self::assertNull($this->violation(fn (Connection $c) => $c->insert('sending_domains', ['id' => SchemaFixtures::id(), 'client_id' => SchemaFixtures::client($c), 'domain' => 'dup.example', 'verification_token' => str_repeat('c', 43)])));
    }

    public function testValidationJobRules(): void
    {
        $client = SchemaFixtures::client($this->c);
        $job = fn (array $o) => fn (Connection $c) => $c->insert('validation_jobs', $o + ['id' => SchemaFixtures::id(), 'client_id' => $client,
            'idempotency_key' => SchemaFixtures::id(), 'request_hash' => 'h', 'total_addresses' => 1]);
        self::assertNull($this->violation($job(['idempotency_key' => 'same-key'])));
        self::assertSame('validation_jobs_client_idempotency_uq', $this->violation($job(['idempotency_key' => 'same-key'])));
        self::assertSame('validation_jobs_total_addresses_check', $this->violation($job(['total_addresses' => 10001])));
        self::assertSame('validation_jobs_total_addresses_check', $this->violation($job(['total_addresses' => 0])));
        self::assertNull($this->violation($job(['total_addresses' => 10000])));
    }

    public function testValidationAddressRules(): void
    {
        $client = SchemaFixtures::client($this->c);
        $jobId = SchemaFixtures::id();
        $this->c->insert('validation_jobs', ['id' => $jobId, 'client_id' => $client, 'idempotency_key' => 'k', 'request_hash' => 'h', 'total_addresses' => 1]);
        $addr = fn (array $o) => fn (Connection $c) => $c->insert('validation_addresses', $o + ['id' => SchemaFixtures::id(), 'job_id' => $jobId, 'original_address' => 'x']);
        self::assertSame('validation_addresses_done_has_result', $this->violation($addr(['processing_state' => 'done'])));
        self::assertSame('validation_addresses_claim_has_lease', $this->violation($addr(['processing_state' => 'claimed'])));
        self::assertSame('validation_addresses_suggestion_complete', $this->violation($addr(['suggested_address' => 'a@gmail.com'])));
        self::assertNull($this->violation($addr(['suggested_address' => 'a@gmail.com', 'suggestion_reason_code' => 'tld_typo', 'suggestion_confidence' => 'high'])));
        self::assertSame('validation_addresses_job_id_fkey', $this->violation($addr(['job_id' => SchemaFixtures::id()])));
    }

    public function testSendJobListIdIsPresentIffSubscription(): void
    {
        $client = SchemaFixtures::client($this->c);
        $domain = SchemaFixtures::domain($this->c, $client);
        self::assertSame('send_jobs_list_id_by_class', $this->violation(fn (Connection $c) => SchemaFixtures::sendJob($c, $client, $domain, ['message_class' => 'subscription'])));
        self::assertSame('send_jobs_list_id_by_class', $this->violation(fn (Connection $c) => SchemaFixtures::sendJob($c, $client, $domain, ['list_id' => 'l.example'])));
        self::assertNull($this->violation(fn (Connection $c) => SchemaFixtures::sendJob($c, $client, $domain, ['message_class' => 'subscription', 'list_id' => 'l.example'])));
        self::assertSame('send_jobs_reply_to_complete', $this->violation(fn (Connection $c) => SchemaFixtures::sendJob($c, $client, $domain, ['reply_to_name' => 'X'])));
    }

    public function testSealRuleAndTimestamps(): void
    {
        $client = SchemaFixtures::client($this->c);
        $domain = SchemaFixtures::domain($this->c, $client);
        self::assertSame('send_jobs_sealed', $this->violation(fn (Connection $c) => SchemaFixtures::sendJob($c, $client, $domain, ['status' => 'queued'])));
        self::assertSame('send_jobs_sealed', $this->violation(fn (Connection $c) => SchemaFixtures::sendJob($c, $client, $domain, ['status' => 'queued', 'queued_at' => '2026-10-03T00:00:00Z'])));
        self::assertNull($this->violation(fn (Connection $c) => SchemaFixtures::sendJob($c, $client, $domain, ['status' => 'queued', 'queued_at' => '2026-10-03T00:00:00Z', 'total_recipients' => 1])));
        self::assertSame('send_jobs_dispatched_has_time', $this->violation(fn (Connection $c) => SchemaFixtures::sendJob($c, $client, $domain, ['status' => 'dispatched', 'queued_at' => '2026-10-03T00:00:00Z', 'total_recipients' => 1])));
        self::assertSame('send_jobs_total_recipients_check', $this->violation(fn (Connection $c) => SchemaFixtures::sendJob($c, $client, $domain, ['total_recipients' => 10001])));
        self::assertNull($this->violation(fn (Connection $c) => SchemaFixtures::sendJob($c, $client, $domain, ['status' => 'cancelled'])));
    }

    public function testBatchAndRecipientRules(): void
    {
        $client = SchemaFixtures::client($this->c);
        $job = SchemaFixtures::sendJob($this->c, $client, SchemaFixtures::domain($this->c, $client));
        self::assertSame('send_job_recipient_batches_recipient_count_check', $this->violation(fn (Connection $c) => SchemaFixtures::batch($c, $job, 501)));
        self::assertSame('send_job_recipient_batches_recipient_count_check', $this->violation(fn (Connection $c) => SchemaFixtures::batch($c, $job, 0)));
        $batch = SchemaFixtures::batch($this->c, $job, 500);
        self::assertSame('send_job_recipient_batches_job_key_uq', $this->violation(fn (Connection $c) => $c->insert('send_job_recipient_batches',
            ['id' => SchemaFixtures::id(), 'send_job_id' => $job, 'idempotency_key' => $c->fetchOne('SELECT idempotency_key FROM send_job_recipient_batches WHERE id = ?', [$batch]), 'request_hash' => 'h', 'recipient_count' => 1])));

        SchemaFixtures::recipient($this->c, $job, $batch, ['normalized_address' => 'John@example.com', 'email_address' => 'John@example.com']);
        self::assertSame('send_job_recipients_job_address_uq', $this->violation(fn (Connection $c) => SchemaFixtures::recipient($c, $job, $batch, ['normalized_address' => 'John@example.com'])));
        self::assertNull($this->violation(fn (Connection $c) => SchemaFixtures::recipient($c, $job, $batch, ['normalized_address' => 'john@example.com'])), 'local part is case-sensitive');
        self::assertSame('send_job_recipients_content_state', $this->violation(fn (Connection $c) => SchemaFixtures::recipient($c, $job, $batch, ['normalized_address' => 'n1@x.example', 'text_body' => null])));
        self::assertSame('send_job_recipients_content_state', $this->violation(fn (Connection $c) => SchemaFixtures::recipient($c, $job, $batch, ['normalized_address' => 'n2@x.example', 'subject' => null])));
        self::assertNull($this->violation(fn (Connection $c) => SchemaFixtures::recipient($c, $job, $batch, ['normalized_address' => 'n3@x.example', 'text_body' => null, 'html_body' => '<p>x</p>'])));
        self::assertSame('send_job_recipients_unsubscribe_url_check', $this->violation(fn (Connection $c) => SchemaFixtures::recipient($c, $job, $batch, ['normalized_address' => 'n4@x.example', 'unsubscribe_url' => 'http://x.example/u'])));
        self::assertSame('send_job_recipients_content_sha256_check', $this->violation(fn (Connection $c) => SchemaFixtures::recipient($c, $job, $batch, ['normalized_address' => 'n5@x.example', 'content_sha256' => 'nothex'])));
        // Purge (D-14): all content NULL plus content_purged_at, size and hash retained.
        $r = SchemaFixtures::recipient($this->c, $job, $batch, ['normalized_address' => 'p@x.example']);
        self::assertNull($this->violation(fn (Connection $c) => $c->executeStatement("UPDATE send_job_recipients SET subject = NULL, html_body = NULL, text_body = NULL, content_purged_at = now() WHERE id = ?", [$r])));
        self::assertSame('send_job_recipients_content_state', $this->violation(fn (Connection $c) => $c->executeStatement('UPDATE send_job_recipients SET subject = NULL WHERE normalized_address = ?', ['n3@x.example'])));
    }

    public function testMessageAndEventRules(): void
    {
        $client = SchemaFixtures::client($this->c);
        $job = SchemaFixtures::sendJob($this->c, $client, SchemaFixtures::domain($this->c, $client));
        $batch = SchemaFixtures::batch($this->c, $job);
        $recipient = SchemaFixtures::recipient($this->c, $job, $batch);
        $message = SchemaFixtures::message($this->c, $job, $recipient);
        self::assertSame('messages_send_job_recipient_uq', $this->violation(fn (Connection $c) => SchemaFixtures::message($c, $job, $recipient)));
        self::assertSame('messages_tracking_token_check', $this->violation(fn (Connection $c) => $c->executeStatement("UPDATE messages SET tracking_token = 'short' WHERE id = ?", [$message])));
        $event = fn (array $o) => fn (Connection $c) => $c->insert('message_events', $o + ['id' => SchemaFixtures::id(), 'message_id' => $message,
            'event_type' => 'deferred', 'event_source' => 'postfix_log', 'source_event_key' => SchemaFixtures::id(), 'occurred_at' => '2026-10-03T00:00:00Z']);
        self::assertNull($this->violation($event(['source_event_key' => 'k1'])));
        self::assertSame('message_events_source_key_uq', $this->violation($event(['source_event_key' => 'k1'])));
        self::assertSame('message_events_transport_keyed', $this->violation($event(['source_event_key' => null])));
        self::assertSame('message_events_tracking_unkeyed', $this->violation($event(['event_type' => 'open_recorded', 'event_source' => 'tracking_endpoint', 'source_event_key' => 'x'])));
        self::assertNull($this->violation($event(['event_type' => 'open_recorded', 'event_source' => 'tracking_endpoint', 'source_event_key' => null])));
        self::assertNull($this->violation($event(['event_type' => 'open_recorded', 'event_source' => 'tracking_endpoint', 'source_event_key' => null])), 'repeated opens are legitimate');
        self::assertSame('message_events_failure_scope_applicable', $this->violation($event(['event_type' => 'remote_accepted', 'failure_scope' => 'recipient'])));
        self::assertSame('message_events_enhanced_status_code_check', $this->violation($event(['enhanced_status_code' => '6.1.1'])));
    }

    public function testSuppressionNormalisationPreservesLocalPartCase(): void
    {
        $s = fn (string $value, string $scope) => fn (Connection $c) => $c->insert('suppressions', ['id' => SchemaFixtures::id(),
            'address_or_domain' => $value, 'scope_type' => $scope, 'reason' => 'hard_bounce']);
        self::assertNull($this->violation($s('John@example.com', 'address')));
        self::assertSame('suppressions_normalised', $this->violation($s('john@Example.com', 'address')));
        self::assertSame('suppressions_normalised', $this->violation($s('Example.com', 'domain')));
        self::assertNull($this->violation($s('example.com', 'domain')));
    }

    public function testWebhookRules(): void
    {
        $client = SchemaFixtures::client($this->c);
        $endpoint = fn (array $o) => fn (Connection $c) => $c->insert('webhook_endpoints', $o + ['id' => SchemaFixtures::id(), 'client_id' => $client,
            'url' => 'https://hooks.example/x', 'subscribed_event_types' => '["send.completed"]', 'signing_secret_ciphertext' => 'c', 'signing_secret_key_id' => 'k']);
        self::assertSame('webhook_endpoints_previous_secret_complete', $this->violation($endpoint(['previous_signing_secret_ciphertext' => 'p'])));
        self::assertSame('webhook_endpoints_subscribed_event_types_check', $this->violation($endpoint(['subscribed_event_types' => '{"a":1}'])));
        self::assertSame('webhook_endpoints_url_check', $this->violation($endpoint(['url' => 'ftp://x'])));
        $subject = SchemaFixtures::id();
        $event = fn (string $type) => fn (Connection $c) => $c->insert('webhook_events', ['id' => SchemaFixtures::id(), 'client_id' => $client,
            'event_type' => $type, 'subject_type' => 'send_job', 'subject_id' => $subject]);
        self::assertNull($this->violation($event('send.completed')));
        self::assertSame('webhook_events_once_uq', $this->violation($event('send.completed')));
        self::assertNull($this->violation($event('webhook.test')));
        self::assertNull($this->violation($event('webhook.test')), 'webhook.test may repeat');
    }

    public function testDomainReputationTreatsNullClientAsOneScope(): void
    {
        $r = fn () => fn (Connection $c) => $c->insert('domain_reputation', ['id' => SchemaFixtures::id(), 'destination_domain' => 'gmail.com', 'last_updated_at' => '2026-10-03T00:00:00Z']);
        self::assertNull($this->violation($r()));
        self::assertSame('domain_reputation_client_domain_uq', $this->violation($r()), 'NULLS NOT DISTINCT: one platform-wide row per domain');
    }

    public function testForeignKeysNeverCascadeHistoryAway(): void
    {
        $client = SchemaFixtures::client($this->c);
        SchemaFixtures::sendJob($this->c, $client, SchemaFixtures::domain($this->c, $client));
        $this->c->executeStatement('SAVEPOINT t');
        try {
            $this->c->executeStatement('DELETE FROM clients WHERE id = ?', [$client]);
            self::fail('Deleting a client with history must fail.');
        } catch (ForeignKeyConstraintViolationException) {
            $this->c->executeStatement('ROLLBACK TO SAVEPOINT t');
        }
        self::assertSame(1, (int) $this->c->fetchOne('SELECT count(*) FROM send_jobs WHERE client_id = ?', [$client]));
    }

    public function testUnmatchedDsnResolutionRules(): void
    {
        $dsn = fn (array $o) => fn (Connection $c) => $c->insert('unmatched_dsns', $o + ['id' => SchemaFixtures::id(), 'received_at' => '2026-10-03T00:00:00Z',
            'spool_ingest_key' => SchemaFixtures::id(), 'content_sha256' => str_repeat('a', 64), 'classification' => 'hard_bounce', 'raw_message' => 'x']);
        self::assertSame('unmatched_dsns_match_requested', $this->violation($dsn(['status' => 'match_requested'])));
        self::assertSame('unmatched_dsns_dismissed_resolved', $this->violation($dsn(['status' => 'dismissed'])));
        self::assertNull($this->violation($dsn(['spool_ingest_key' => 'same'])));
        self::assertSame('unmatched_dsns_spool_key_uq', $this->violation($dsn(['spool_ingest_key' => 'same'])));
    }

    #[DataProvider('lowercaseDomains')]
    public function testLowercaseDomainChecks(string $table, array $row): void
    {
        self::assertNotNull($this->violation(fn (Connection $c) => $c->insert($table, $row)));
    }

    public static function lowercaseDomains(): iterable
    {
        yield 'disposable' => ['disposable_domains', ['domain' => 'Mailinator.com', 'source' => 'test']];
        yield 'reputation' => ['domain_reputation', ['id' => '01999999-0000-7000-8000-000000000001', 'destination_domain' => 'Gmail.com', 'last_updated_at' => '2026-10-03T00:00:00Z']];
    }
}
