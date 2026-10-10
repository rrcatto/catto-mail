<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Util\InstallationTime;
use App\Dashboard\OverviewPeriod;
use PHPUnit\Framework\TestCase;

/** The installation's time zone (SMARTHOST_TIMEZONE), the clock and the overview periods built on it. */
final class InstallationTimeTest extends TestCase
{
    public function testTimesAreShownInTheConfiguredZone(): void
    {
        $time = new InstallationTime('Africa/Johannesburg');
        self::assertSame('Africa/Johannesburg', $time->name());
        self::assertSame('SAST', $time->abbreviation());
        self::assertSame('2026-10-10 01:30:00 SAST', $time->local('2026-10-09 23:30:00+00')?->format('Y-m-d H:i:s T'));
        self::assertNull($time->local(null));
    }

    public function testAnUnknownZoneIsAStartupError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SMARTHOST_TIMEZONE');
        new InstallationTime('Mars/Olympus_Mons');
    }

    public function testAnOffsetOrAbbreviationIsNotAZone(): void
    {
        foreach (['+02:00', 'SAST', 'UTC+2', ''] as $bad) {
            try {
                new InstallationTime($bad);
                self::fail("'$bad' was accepted");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheClockAndTheApiFormatUseTheInstallationZone(): void
    {
        $previous = date_default_timezone_get();
        try {
            date_default_timezone_set('Africa/Johannesburg');
            self::assertSame('+02:00', \App\Util\Clock::now()->format('P'));
            self::assertSame('2026-10-10T10:48:56.123456+02:00',
                \App\Util\Clock::rfc3339(new \DateTimeImmutable('2026-10-10T08:48:56.123456Z')), 'API timestamps carry the SAST offset');
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testPeriodsAreWholeBucketsOfTheLocalDayOrHour(): void
    {
        $zone = new \DateTimeZone('Africa/Johannesburg');
        // 23:30 UTC on 9 October is already 01:30 on 10 October in Johannesburg.
        $now = new \DateTimeImmutable('2026-10-09T23:30:00Z');

        $week = OverviewPeriod::fromKey('7d', $zone, $now);
        self::assertSame('2026-10-04T00:00:00+02:00', $week->since->format(\DATE_ATOM));
        self::assertSame('2026-09-27T00:00:00+02:00', $week->previousSince->format(\DATE_ATOM));
        self::assertSame('2026-10-04 00:00', $week->bucketKeys()[0]);
        self::assertSame('2026-10-10 00:00', $week->bucketKeys()[6]);
        self::assertSame('10 Oct', $week->bucketLabel('2026-10-10 00:00'));
        self::assertSame('SAST', $week->zoneAbbreviation());

        $day = OverviewPeriod::fromKey('24h', $zone, $now);
        self::assertSame('2026-10-09T02:00:00+02:00', $day->since->format(\DATE_ATOM));
        self::assertCount(24, $day->bucketKeys());
        self::assertSame('2026-10-10 01:00', $day->bucketKeys()[23], 'the current local hour is the last bucket');

        self::assertSame('7d', OverviewPeriod::fromKey('nonsense', $zone, $now)->key);
    }
}
