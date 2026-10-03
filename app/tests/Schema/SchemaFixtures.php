<?php

declare(strict_types=1);

namespace App\Tests\Schema;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Minimal raw rows for constraint tests (owner connection, rolled back by the caller). */
final class SchemaFixtures
{
    public static function id(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    public static function client(Connection $c): string
    {
        $id = self::id();
        $c->insert('clients', ['id' => $id, 'company_name' => 'Fixture', 'contact_email' => 'f@example.test', 'plan' => 'test']);

        return $id;
    }

    public static function domain(Connection $c, string $client): string
    {
        $id = self::id();
        $c->insert('sending_domains', ['id' => $id, 'client_id' => $client, 'domain' => 'd'.bin2hex(random_bytes(3)).'.example',
            'verification_token' => str_repeat('a', 43)]);

        return $id;
    }

    /** @param array<string, mixed> $overrides */
    public static function sendJob(Connection $c, string $client, string $domain, array $overrides = []): string
    {
        $id = self::id();
        $c->insert('send_jobs', $overrides + ['id' => $id, 'client_id' => $client, 'external_reference' => 'x', 'idempotency_key' => self::id(),
            'request_hash' => 'h', 'message_class' => 'transactional', 'sending_domain_id' => $domain, 'sender_email' => 's@x.example']);

        return $overrides['id'] ?? $id;
    }

    public static function batch(Connection $c, string $job, int $count = 1): string
    {
        $id = self::id();
        $c->insert('send_job_recipient_batches', ['id' => $id, 'send_job_id' => $job, 'idempotency_key' => self::id(), 'request_hash' => 'h',
            'recipient_count' => $count]);

        return $id;
    }

    /** @param array<string, mixed> $overrides */
    public static function recipient(Connection $c, string $job, string $batch, array $overrides = []): string
    {
        $id = self::id();
        $c->insert('send_job_recipients', $overrides + ['id' => $id, 'send_job_id' => $job, 'batch_id' => $batch,
            'external_recipient_reference' => 'r', 'email_address' => 'a@b.example', 'normalized_address' => 'a@b.example',
            'subject' => 's', 'text_body' => 't', 'content_bytes' => 2, 'content_sha256' => str_repeat('0', 64)]);

        return $id;
    }

    public static function message(Connection $c, string $job, string $recipient): string
    {
        $id = self::id();
        $c->insert('messages', ['id' => $id, 'send_job_id' => $job, 'send_job_recipient_id' => $recipient,
            'external_recipient_reference' => 'r', 'recipient_address' => 'a@b.example', 'verp_token' => self::id(),
            'return_path' => 'bounce+x@bounce.example']);

        return $id;
    }
}
