<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\AddressBatch\AddressBatchService;
use App\AddressBatch\BatchFileParser;
use App\AddressBatch\RepermissionService;
use App\Enum\AddressBatchPurpose;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use App\Webhook\WebhookPayloadFactory;

/**
 * The public re-permission page /p/{token} (specification 2.11): opening a link never
 * answers; answers are POSTs, idempotent and audited; an unsubscribe is the list's own
 * (no suppression); a global opt-out creates the D-30 global suppression and is final; the
 * client learns each answer through repermission.responded; expired and unknown tokens get
 * one neutral page; no cookie, no address in any URL; RFC 8058 one-click unsubscribes.
 */
final class RepermissionTest extends DashboardTestCase
{
    /** @return array{0: string, 1: string, 2: string, 3: string} batch id, entry id, token, client id */
    private function entryWithToken(string $address = 'reader@example.org'): array
    {
        $client = $this->newClient();
        $admin = $this->newUser(false, null, roleKey: 'ADMIN');
        $batch = $this->container()->get(AddressBatchService::class)->import($this->reload($client), BatchFileParser::parse("$address\n", 'l.txt'),
            'l.txt', 'Old list', null, null, AddressBatchPurpose::Repermission, 'news.example.org', $this->reload($admin));
        $entry = (string) Db::owner()->fetchOne('SELECT id FROM address_batch_entries WHERE batch_id = ?', [$batch]);
        $url = $this->container()->get(RepermissionService::class)->issue($entry);
        self::assertMatchesRegularExpression('#^https://smarthost\.localhost/p/[A-Za-z0-9_-]{43}$#', $url);

        return [$batch, $entry, substr($url, \strlen('https://smarthost.localhost/p/')), $client->getId()->toRfc4122()];
    }

    private function post(string $token, array $fields): \Symfony\Component\HttpFoundation\Response
    {
        $this->browser->request('POST', "/p/$token", $fields);

        return $this->browser->getResponse();
    }

    public function testOpeningTheLinkShowsTheChoicesWithoutAnswering(): void
    {
        [, $entry, $token] = $this->entryWithToken();
        $r = $this->page("/p/$token?choice=unsubscribe");
        self::assertSame(200, $r->getStatusCode());
        $text = self::text($r);
        foreach (['Yes, keep me subscribed', 'Unsubscribe me from this list', 'Never send me any e-mail through this service'] as $choice) {
            self::assertStringContainsString($choice, $text);
        }
        self::assertStringNotContainsString('reader@example.org', (string) $r->getContent(), 'the address is never shown');
        self::assertSame([], $r->headers->getCookies(), 'no cookie');
        self::assertStringContainsString("default-src 'none'", (string) $r->headers->get('Content-Security-Policy'));
        self::assertSame('unconfirmed', Db::owner()->fetchOne('SELECT consent_state FROM address_batch_entries WHERE id = ?', [$entry]), 'GET never answers');
        self::assertSame(404, $this->page('/p/'.str_repeat('A', 43))->getStatusCode(), 'an unknown token');
        self::assertSame(404, $this->page('/p/short')->getStatusCode());
    }

    public function testAnswersAreIdempotentAuditedAndForwarded(): void
    {
        [, $entry, $token, $client] = $this->entryWithToken();
        $o = Db::owner();
        self::assertStringContainsString('You stay subscribed', self::text($this->post($token, ['answer' => 'confirm'])));
        self::assertSame('confirmed', $o->fetchOne('SELECT consent_state FROM address_batch_entries WHERE id = ?', [$entry]));
        self::assertStringContainsString('already your answer', self::text($this->post($token, ['answer' => 'confirm'])));
        self::assertSame(1, (int) $o->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'repermission.responded' AND target_id = ?", [$entry]));
        self::assertSame(1, (int) $o->fetchOne("SELECT count(*) FROM webhook_events WHERE event_type = 'repermission.responded' AND subject_id = ?", [$entry]));

        // Changing the answer: an unsubscribe from this list is the client's state only, never a suppression.
        $this->post($token, ['answer' => 'unsubscribe']);
        self::assertSame('unsubscribed', $o->fetchOne('SELECT consent_state FROM address_batch_entries WHERE id = ?', [$entry]));
        self::assertSame(0, (int) $o->fetchOne("SELECT count(*) FROM suppressions WHERE address_or_domain = 'reader@example.org'"));

        // The webhook payload carries the current answer for the client application.
        $event = $o->fetchAssociative("SELECT id::text AS id, event_type, subject_type, subject_id::text AS subject_id, created_at, client_id::text AS client_id
            FROM webhook_events WHERE subject_id = ? ORDER BY created_at DESC LIMIT 1", [$entry]);
        $payload = $this->container()->get(WebhookPayloadFactory::class)->build($event);
        self::assertSame(['response' => 'unsubscribed', 'address' => 'reader@example.org', 'list_id' => 'news.example.org'],
            ['response' => $payload['data']['response'], 'address' => $payload['data']['address'], 'list_id' => $payload['data']['batch']['list_id']]);
        self::assertSame($client, $event['client_id']);
    }

    public function testGlobalOptOutSuppressesEverywhereAndIsFinal(): void
    {
        [$batch, $entry, $token, $client] = $this->entryWithToken('leave@example.org');
        $o = Db::owner();
        self::assertStringContainsString('No e-mail will be sent', self::text($this->post($token, ['answer' => 'global_opt_out'])));
        $s = $o->fetchAssociative("SELECT client_id, reason, scope_type, source_client_id::text AS source, external_reference FROM suppressions WHERE address_or_domain = 'leave@example.org'");
        self::assertSame([null, 'recipient_global_opt_out', 'address', $client, "repermission:$entry"], array_values($s), 'global, reported for this client (D-30)');
        $this->post($token, ['answer' => 'confirm']);
        self::assertSame('global_opt_out', $o->fetchOne('SELECT consent_state FROM address_batch_entries WHERE id = ?', [$entry]), 'final on the page');
        self::assertSame(1, (int) $o->fetchOne("SELECT count(*) FROM suppressions WHERE address_or_domain = 'leave@example.org'"));
        self::assertStringContainsString('final', self::text($this->page("/p/$token")));
        self::assertNotEmpty($batch);
    }

    public function testOneClickUnsubscribeAndExpiry(): void
    {
        [, $entry, $token] = $this->entryWithToken();
        $r = $this->post($token, ['List-Unsubscribe' => 'One-Click']);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('unsubscribed', Db::owner()->fetchOne('SELECT consent_state FROM address_batch_entries WHERE id = ?', [$entry]));

        [, $entry2, $token2] = $this->entryWithToken();
        Db::owner()->executeStatement("UPDATE address_batch_entries SET response_token_expires_at = now() - interval '1 minute' WHERE id = ?", [$entry2]);
        self::assertSame(404, $this->page("/p/$token2")->getStatusCode());
        self::assertSame(404, $this->post($token2, ['answer' => 'confirm'])->getStatusCode());
        self::assertSame('unconfirmed', Db::owner()->fetchOne('SELECT consent_state FROM address_batch_entries WHERE id = ?', [$entry2]));
        self::assertStringContainsString('test message', self::text($this->page('/p/seed-test')), 'seed-test links explain themselves');
    }
}
