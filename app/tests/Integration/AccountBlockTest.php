<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\ClientMembershipRole;
use App\Enum\ClientStatus;
use App\Enum\SuppressionReason;
use App\Enum\SuppressionScopeType;
use App\Suppression\SuppressionAdministration;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;

/**
 * Account blocks (specification 2.13, owner decision 2026-10-27): an account blocks an
 * address or a whole domain for its own mail, through /v1/blocks or its dashboard. Only its
 * own blocks are listed; other accounts and the installation's suppressions are untouched.
 */
final class AccountBlockTest extends DashboardTestCase
{
    private function block(string $key, array $body, ?string $idem = null): \Symfony\Component\HttpFoundation\Response
    {
        return $this->api('POST', '/v1/blocks', $key, $body, ['Idempotency-Key' => $idem ?? self::key()]);
    }

    private static function tag(): string
    {
        return bin2hex(random_bytes(4));
    }

    public function testAnAccountBlocksAnAddressAndADomainIdempotently(): void
    {
        [$client, $key] = $this->newApiClient();
        $tag = self::tag();
        $idem = self::key();
        $created = $this->assertContract($this->block($key, ['address' => " Someone.$tag@Example.COM ", 'external_reference' => 'ctnlist-1'], $idem), 201, '/blocks', 'post');
        self::assertSame(['address', "Someone.$tag@example.com", 'active', 'ctnlist-1'],
            [$created['scope'], $created['value'], $created['status'], $created['external_reference']], 'normalised: domain lower-cased, local part kept');

        // The same key and body replays; another key for the same value finds the active block (200).
        $replay = $this->block($key, ['address' => " Someone.$tag@Example.COM ", 'external_reference' => 'ctnlist-1'], $idem);
        self::assertSame($created['id'], $this->assertContract($replay, 201, '/blocks', 'post')['id']);
        self::assertSame('true', $replay->headers->get('Idempotent-Replayed'));
        $again = $this->assertContract($this->block($key, ['address' => "Someone.$tag@example.com"]), 200, '/blocks', 'post');
        self::assertSame($created['id'], $again['id']);
        $this->assertProblem($this->block($key, ['address' => "other.$tag@example.com"], $idem), 422, 'idempotency-key-reused');

        $domain = $this->assertContract($this->block($key, ['domain' => "Spam-$tag.Example."]), 201, '/blocks', 'post');
        self::assertSame(['domain', "spam-$tag.example"], [$domain['scope'], $domain['value']]);

        $row = Db::owner()->fetchAssociative('SELECT client_id::text AS client_id, reason, scope_type, expires_at FROM suppressions WHERE id = ?', [$domain['id']]);
        self::assertSame([$client->getId()->toRfc4122(), 'account_block', 'domain', null], array_values($row), 'scoped to the account, never global, never expires');
        self::assertSame(2, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'suppression.account_block_created' AND target_id IN (?, ?)",
            [$created['id'], $domain['id']]));
        self::assertSame(2, (int) Db::owner()->fetchOne("SELECT count(*) FROM global_suppression_requests WHERE operation = 'create_account_block' AND suppression_id = ?",
            [$created['id']]), 'both accepted requests are recorded');
    }

    public function testExactlyOneUsableValueIsRequired(): void
    {
        [, $key] = $this->newApiClient();
        foreach ([[], ['address' => 'a@example.org', 'domain' => 'example.org'], ['address' => 'no-at-sign'],
            ['domain' => 'user@example.org'], ['domain' => 'not a domain'], ['address' => 'a@example.org', 'reason' => 'hard_bounce'],
            ['address' => 'a@example.org', 'client_id' => '01920000-0000-7000-8000-000000000001']] as $body) {
            $this->assertProblem($this->block($key, $body), 422);
        }
        self::assertSame(0, (int) Db::owner()->fetchOne("SELECT count(*) FROM suppressions WHERE reason = 'account_block' AND client_id IN (SELECT client_id FROM api_keys WHERE key_prefix = ?)",
            [substr($key, 0, 12)]));
    }

    public function testTheListShowsOnlyTheAccountsOwnBlocks(): void
    {
        [, $key] = $this->newApiClient();
        [, $otherKey] = $this->newApiClient();
        $tag = self::tag();
        $first = $this->assertContract($this->block($key, ['address' => "one.$tag@example.org"]), 201, '/blocks', 'post');
        $second = $this->assertContract($this->block($key, ['domain' => "two-$tag.example"]), 201, '/blocks', 'post');
        $foreign = $this->assertContract($this->block($otherKey, ['address' => "one.$tag@example.org"]), 201, '/blocks', 'post');
        self::assertNotSame($first['id'], $foreign['id'], 'each account has its own block of the same address');
        // The installation's own suppressions are never listed.
        $this->service(SuppressionAdministration::class)->create(null, "one.$tag@example.org", SuppressionScopeType::Address, SuppressionReason::OperatorBlock, null, $this->newUser(true), 'test');

        $page = $this->assertContract($this->api('GET', '/v1/blocks?limit=1', $key), 200, '/blocks', 'get');
        self::assertSame([$second['id']], array_column($page['data'], 'id'), 'newest first');
        self::assertNotNull($page['pagination']['next_cursor']);
        $next = $this->assertContract($this->api('GET', '/v1/blocks?limit=1&cursor='.urlencode($page['pagination']['next_cursor']), $key), 200, '/blocks', 'get');
        self::assertSame([$first['id']], array_column($next['data'], 'id'));
        self::assertNull($next['pagination']['next_cursor']);

        $byValue = $this->assertContract($this->api('GET', '/v1/blocks?value='.urlencode("ONE.$tag@Example.org"), $key), 200, '/blocks', 'get');
        self::assertSame([], array_column($byValue['data'], 'id'), 'the local part is never case-folded');
        $byValue = $this->assertContract($this->api('GET', '/v1/blocks?value='.urlencode("one.$tag@Example.ORG"), $key), 200, '/blocks', 'get');
        self::assertSame([$first['id']], array_column($byValue['data'], 'id'));
        $this->assertProblem($this->api('GET', '/v1/blocks?status=everything', $key), 400);

        $this->assertProblem($this->api('GET', '/v1/blocks/'.$foreign['id'], $key), 404, 'not-found');
        $this->assertProblem($this->api('POST', '/v1/blocks/'.$foreign['id'].'/lift', $key), 404, 'not-found');
        self::assertNull(Db::owner()->fetchOne('SELECT lifted_at FROM suppressions WHERE id = ?', [$foreign['id']]), 'another account\'s block is untouched');
    }

    public function testLiftingNeedsAnActiveAccountButBlockingDoesNot(): void
    {
        [$client, $key] = $this->newApiClient();
        $tag = self::tag();
        $this->setClientStatus($client, ClientStatus::Suspended);
        $block = $this->assertContract($this->block($key, ['address' => "late.$tag@example.org"]), 201, '/blocks', 'post');
        $p = $this->assertProblem($this->api('POST', "/v1/blocks/{$block['id']}/lift", $key), 403, 'forbidden');
        self::assertStringContainsString('may not lift', $p['detail']);

        $this->setClientStatus($client, ClientStatus::Active);
        $lifted = $this->assertContract($this->api('POST', "/v1/blocks/{$block['id']}/lift", $key), 200, '/blocks/{id}/lift', 'post');
        self::assertSame('lifted', $lifted['status']);
        self::assertSame($lifted, $this->assertContract($this->api('POST', "/v1/blocks/{$block['id']}/lift", $key), 200, '/blocks/{id}/lift', 'post'), 'idempotent');
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'suppression.account_block_lifted' AND target_id = ?", [$block['id']]));
        $lists = $this->assertContract($this->api('GET', '/v1/blocks?status=lifted', $key), 200, '/blocks', 'get');
        self::assertSame([$block['id']], array_column($lists['data'], 'id'));

        // A new block of the same value after the lift is a new row.
        $renewed = $this->assertContract($this->block($key, ['address' => "late.$tag@example.org"]), 201, '/blocks', 'post');
        self::assertNotSame($block['id'], $renewed['id']);
    }

    public function testAnAdminBlocksAndLiftsInTheDashboard(): void
    {
        $client = $this->newClient();
        $cid = $client->getId()->toRfc4122();
        $base = "/dashboard/c/$cid";
        $tag = self::tag();

        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Viewer));
        self::assertSame(404, $this->page("$base/suppressions/new")->getStatusCode(), 'only admins block (a page beyond the role is missing)');
        self::assertSame(0, $this->crawler("$base/suppressions")->filter("a[href=\"$base/suppressions/new\"]")->count());

        $this->browser->getCookieJar()->clear();
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));
        self::assertSame(1, $this->crawler("$base/suppressions")->filter("a[href=\"$base/suppressions/new\"]")->count());
        $bad = $this->submit("$base/suppressions/new", "$base/suppressions/new", ['value' => 'not a domain']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('not a domain name', self::text($bad));

        $this->submit("$base/suppressions/new", "$base/suppressions/new", ['value' => "@Bad-$tag.Example"]);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $this->submit("$base/suppressions/new", "$base/suppressions/new", ['value' => "Person.$tag@Example.org"]);
        $rows = Db::owner()->fetchAllAssociative("SELECT id::text AS id, address_or_domain, scope_type FROM suppressions WHERE client_id = ? AND reason = 'account_block' ORDER BY created_at", [$cid]);
        self::assertSame([["bad-$tag.example", 'domain'], ["Person.$tag@example.org", 'address']],
            array_map(static fn (array $r): array => [$r['address_or_domain'], $r['scope_type']], $rows));

        // Searching an address also finds the domain block that covers it.
        $found = $this->crawler("$base/suppressions?address=".urlencode("anyone@bad-$tag.example"))->filter('tbody')->first()->text();
        self::assertStringContainsString("bad-$tag.example", $found);
        self::assertStringContainsString('Account block', $found);

        $response = $this->submit("$base/suppressions", "$base/blocks/{$rows[0]['id']}/lift");
        self::assertSame(302, $response->getStatusCode());
        self::assertNotNull(Db::owner()->fetchOne('SELECT lifted_at FROM suppressions WHERE id = ?', [$rows[0]['id']]));
        $this->browser->request('POST', "$base/blocks/{$rows[1]['id']}/lift", ['_token' => 'forged']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode(), 'CSRF');
        self::assertNull(Db::owner()->fetchOne('SELECT lifted_at FROM suppressions WHERE id = ?', [$rows[1]['id']]));
    }

    public function testTheDatabaseKeepsAnAccountBlockScoped(): void
    {
        $cid = $this->newClient()->getId()->toRfc4122();
        self::assertNull(self::insertBlock($cid, null));
        self::assertStringContainsString('suppressions_account_block_scoped', (string) self::insertBlock(null, null), 'never global');
        self::assertStringContainsString('suppressions_account_block_scoped', (string) self::insertBlock($cid, '2099-01-01T00:00:00Z'), 'never expires');
    }

    /** Inserts an account_block row directly; the violated constraint's message, or null. */
    private static function insertBlock(?string $clientId, ?string $expiresAt): ?string
    {
        try {
            Db::owner()->insert('suppressions', ['id' => \Symfony\Component\Uid\Uuid::v7()->toRfc4122(), 'client_id' => $clientId,
                'address_or_domain' => 'scoped-'.bin2hex(random_bytes(3)).'.example', 'scope_type' => 'domain', 'reason' => 'account_block', 'expires_at' => $expiresAt]);

            return null;
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            return $e->getMessage();
        }
    }
}
