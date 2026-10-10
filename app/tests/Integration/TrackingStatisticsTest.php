<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Dashboard\ClientReadModel;
use App\Dashboard\Listing;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\DashboardFixtures;
use App\Tests\Support\Db;

/**
 * Recorded-open/click aggregates against a known event set. Every event is kept;
 * "messages with a recorded open" counts messages with at least one open event,
 * and a click never implies an open.
 */
final class TrackingStatisticsTest extends ApiTestCase
{
    public function testAggregatesMatchTheEventSet(): void
    {
        $client = $this->newClient();
        $clientId = $client->getId()->toRfc4122();
        $o = Db::owner();
        $set = DashboardFixtures::sendJob($o, $clientId, $this->verifiedDomain($client)->getId()->toRfc4122(), 6, ['track_opens' => true, 'track_clicks' => true]);
        [$none, $oneOpen, $manyOpens, $clickOnly, $openAndClicks, $clickBeforeOpen] = $set['messages'];
        foreach ([$oneOpen, $manyOpens, $clickOnly, $openAndClicks, $clickBeforeOpen] as $m) {
            DashboardFixtures::link($o, $m, 1, 'https://a.example.test/one');
            DashboardFixtures::link($o, $m, 2, 'https://a.example.test/two');
        }
        DashboardFixtures::trackingEvent($o, $oneOpen, 'open_recorded', '2026-10-01 08:00:00');
        foreach (['2026-10-01 09:00:00', '2026-10-02 09:00:00', '2026-10-03 09:00:00'] as $t) {
            DashboardFixtures::trackingEvent($o, $manyOpens, 'open_recorded', $t);
        }
        DashboardFixtures::trackingEvent($o, $clickOnly, 'click_recorded', '2026-10-01 10:00:00', 1);
        DashboardFixtures::trackingEvent($o, $openAndClicks, 'open_recorded', '2026-10-01 11:00:00');
        DashboardFixtures::trackingEvent($o, $openAndClicks, 'click_recorded', '2026-10-01 11:01:00', 1);
        DashboardFixtures::trackingEvent($o, $openAndClicks, 'click_recorded', '2026-10-01 11:02:00', 2);
        // Automated ordering: the click arrives before the (image-proxied) open.
        DashboardFixtures::trackingEvent($o, $clickBeforeOpen, 'click_recorded', '2026-10-04 12:00:00', 2);
        DashboardFixtures::trackingEvent($o, $clickBeforeOpen, 'open_recorded', '2026-10-04 12:05:00');

        $read = $this->container()->get(ClientReadModel::class);
        $e = $read->jobEngagement($set['job']);
        self::assertSame(6, (int) $e['open_events'], '1 + 3 + 1 + 1');
        self::assertSame(4, (int) $e['opened_messages'], 'the click-only message is not counted as opened');
        self::assertSame(4, (int) $e['click_events']);
        self::assertSame(3, (int) $e['clicked_messages']);
        self::assertStringStartsWith('2026-10-01 08:00:00', (string) $e['first_open']);
        self::assertStringStartsWith('2026-10-04 12:05:00', (string) $e['last_open']);
        self::assertStringStartsWith('2026-10-01 10:00:00', (string) $e['first_click']);
        self::assertStringStartsWith('2026-10-04 12:00:00', (string) $e['last_click']);
        $byLink = array_column($e['links'], null, 'link_index');
        self::assertSame(['clicks' => 2, 'messages' => 2, 'target' => 'https://a.example.test/one'],
            ['clicks' => (int) $byLink[1]['clicks'], 'messages' => (int) $byLink[1]['messages'], 'target' => $byLink[1]['target_url']]);
        self::assertSame(['clicks' => 2, 'messages' => 2, 'target' => 'https://a.example.test/two'],
            ['clicks' => (int) $byLink[2]['clicks'], 'messages' => (int) $byLink[2]['messages'], 'target' => $byLink[2]['target_url']]);

        $rows = array_column($read->messages($clientId, $set['job'], [], Listing::first('created', 'asc', 50))['rows'], null, 'id');
        self::assertSame([0, 0], [(int) $rows[$none]['opens'], (int) $rows[$none]['clicks']]);
        self::assertNull($rows[$none]['first_open']);
        self::assertSame([3, 0], [(int) $rows[$manyOpens]['opens'], (int) $rows[$manyOpens]['clicks']]);
        self::assertStringStartsWith('2026-10-01 09:00:00', (string) $rows[$manyOpens]['first_open']);
        self::assertStringStartsWith('2026-10-03 09:00:00', (string) $rows[$manyOpens]['last_open']);
        self::assertSame([0, 1], [(int) $rows[$clickOnly]['opens'], (int) $rows[$clickOnly]['clicks']], 'clicked without a recorded open');
        self::assertSame([1, 2], [(int) $rows[$openAndClicks]['opens'], (int) $rows[$openAndClicks]['clicks']]);
        self::assertSame([1, 1], [(int) $rows[$clickBeforeOpen]['opens'], (int) $rows[$clickBeforeOpen]['clicks']]);

        // Every event is kept: nothing was de-duplicated away by the aggregation.
        self::assertSame(10, (int) $o->fetchOne("SELECT count(*) FROM message_events e JOIN messages m ON m.id = e.message_id WHERE m.send_job_id = ? AND e.event_source = 'tracking_endpoint'", [$set['job']]));

        $overview = $read->overview($clientId, \App\Dashboard\OverviewPeriod::fromKey('30d', $this->container()->get(\App\Util\InstallationTime::class)->zone));
        self::assertSame(['open_events' => 6, 'opened_messages' => 4, 'click_events' => 4, 'clicked_messages' => 3],
            array_map('intval', $overview['engagement']));
    }

    public function testNoEventsGiveZeroAndNull(): void
    {
        $client = $this->newClient();
        $set = DashboardFixtures::sendJob(Db::owner(), $client->getId()->toRfc4122(), $this->verifiedDomain($client)->getId()->toRfc4122(), 2, ['track_opens' => true]);
        $e = $this->container()->get(ClientReadModel::class)->jobEngagement($set['job']);
        self::assertSame([0, 0, 0, 0], [(int) $e['open_events'], (int) $e['opened_messages'], (int) $e['click_events'], (int) $e['clicked_messages']]);
        self::assertNull($e['first_open']);
        self::assertSame([], $e['links']);
    }
}
