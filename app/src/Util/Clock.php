<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Current time with microseconds in the installation's zone (the PHP default zone, set from
 * SMARTHOST_TIMEZONE on boot): the single time source of the application.
 */
final class Clock
{
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::zone());
    }

    /**
     * RFC 3339 with microseconds and the installation zone's offset, as used by the API, webhook
     * payloads and exports (conventions: Identifiers and time), e.g. 2026-10-10T10:48:56.123456+02:00.
     */
    public static function rfc3339(?\DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(self::zone())->format('Y-m-d\TH:i:s.uP');
    }

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(date_default_timezone_get());
    }
}
