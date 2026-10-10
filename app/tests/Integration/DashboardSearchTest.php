<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\ClientMembershipRole;
use App\Enum\ClientStatus;
use App\Tests\Schema\SchemaFixtures;
use App\Tests\Support\DashboardFixtures;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;

/**
 * The dashboard redesign's new figures and tools: the top bar search (operator scope by
 * permission key, client scope by tenant), the submission-rate chart and the client
 * overview's period choice.
 */
final class DashboardSearchTest extends DashboardTestCase
{
    public function testOperatorSearchFindsClientsJobsAndMessages(): void
    {
        $tag = bin2hex(random_bytes(3));
        $client = $this->newClient(ClientStatus::Active, "Searchable $tag Ltd");
        $o = Db::owner();
        $set = DashboardFixtures::sendJob($o, $client->getId()->toRfc4122(), $this->verifiedDomain($client)->getId()->toRfc4122(), 2,
            ['external_reference' => "newsletter-$tag"]);
        $address = (string) $o->fetchOne('SELECT recipient_address FROM messages WHERE id = ?', [$set['messages'][0]]);
        $this->signIn($this->newUser(true));

        self::assertStringContainsString("Searchable $tag Ltd", self::text($this->page('/dashboard/search?q='.urlencode("searchable $tag"))));
        $jobs = $this->crawler('/dashboard/search?q='.urlencode("newsletter-$tag"));
        self::assertSame(1, $jobs->filter('section[aria-labelledby="res-send_jobs"] .result-list li')->count());
        self::assertStringContainsString('/send-jobs/'.$set['job'], (string) $jobs->filter('section[aria-labelledby="res-send_jobs"] .result-list a')->attr('href'));
        self::assertStringContainsString($address, self::text($this->page('/dashboard/search?q='.urlencode($set['messages'][0]))), 'a message by its id');
        self::assertStringContainsString("newsletter-$tag", self::text($this->page('/dashboard/search?q='.urlencode($address))), 'a message by its recipient');
        self::assertStringContainsString('at least two characters', self::text($this->page('/dashboard/search?q=x')));
        self::assertStringContainsString('Nothing matches', self::text($this->page('/dashboard/search?q=no-such-thing-'.$tag)));
    }

    public function testClientSearchStaysInsideTheClient(): void
    {
        $tag = bin2hex(random_bytes(3));
        $mine = $this->newClient(ClientStatus::Active, "Mine $tag");
        $other = $this->newClient(ClientStatus::Active, "Other $tag");
        $o = Db::owner();
        $own = DashboardFixtures::sendJob($o, $mine->getId()->toRfc4122(), $this->verifiedDomain($mine)->getId()->toRfc4122(), 1, ['external_reference' => "promo-$tag-a"]);
        $foreign = DashboardFixtures::sendJob($o, $other->getId()->toRfc4122(), $this->verifiedDomain($other)->getId()->toRfc4122(), 1, ['external_reference' => "promo-$tag-b"]);
        $this->signIn($this->newUser(false, $mine, ClientMembershipRole::Viewer));
        $base = '/dashboard/c/'.$mine->getId()->toRfc4122();

        $html = (string) $this->page("$base/search?q=".urlencode("promo-$tag"))->getContent();
        self::assertStringContainsString("promo-$tag-a", $html);
        self::assertStringNotContainsString("promo-$tag-b", $html, 'never another client\'s job');
        self::assertSame(0, $this->crawler("$base/search?q=".$foreign['messages'][0])->filter('.result-list li')->count(), 'not another client\'s message');
        self::assertStringContainsString($own['messages'][0], (string) $this->page("$base/search?q=".$own['messages'][0])->getContent());
        self::assertSame(404, $this->page('/dashboard/c/'.$other->getId()->toRfc4122().'/search?q=promo')->getStatusCode());
        self::assertSame(403, $this->page('/dashboard/search?q=promo')->getStatusCode(), 'the installation search is for operators');
    }

    public function testSubmissionRateAndPeriodChoices(): void
    {
        $client = $this->newClient(ClientStatus::Active, 'Rate Co');
        $o = Db::owner();
        $set = DashboardFixtures::sendJob($o, $client->getId()->toRfc4122(), $this->verifiedDomain($client)->getId()->toRfc4122(), 3);
        foreach ($set['messages'] as $i => $message) {
            $o->insert('message_events', ['id' => SchemaFixtures::id(), 'message_id' => $message, 'event_type' => 'submitted_to_postfix',
                'event_source' => 'postfix_submission', 'source_event_key' => 'rate-'.$message, 'occurred_at' => gmdate('Y-m-d H:i:00')]);
        }
        $this->signIn($this->newUser(true));

        $band = json_decode((string) $this->crawler('/dashboard/operator?period=24h')->filter('canvas[data-chart-kind-value="band"]')->attr('data-chart-config-value'), true);
        self::assertCount(24, $band['peak']);
        self::assertGreaterThanOrEqual(3, $band['peak'][23], 'three submissions in the same minute of the current hour');
        $delivery = $this->crawler('/dashboard/operator/system?period=7d');
        self::assertSame(1, $delivery->filter('canvas[data-chart-kind-value="band"]')->count());
        self::assertSame('7 days', $delivery->filter('nav.seg a[aria-current]')->text());

        // The client overview has its own period choice and figures from its own jobs only.
        $overview = $this->crawler('/dashboard/c/'.$client->getId()->toRfc4122().'?period=30d');
        self::assertSame('30 days', $overview->filter('.pills a[aria-current]')->text());
        $flow = json_decode((string) $overview->filter('canvas[data-chart-kind-value="flow"]')->attr('data-chart-config-value'), true);
        self::assertCount(30, $flow['totals']);
        self::assertSame(3, array_sum($flow['totals']), 'only this client\'s three messages');
        self::assertSame(4, $overview->filter('.kpi')->count());
    }
}
