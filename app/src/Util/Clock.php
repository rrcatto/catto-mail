<?php

declare(strict_types=1);

namespace App\Util;

/** Current time in UTC with microseconds (the single time source of the application). */
final class Clock
{
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** RFC 3339 in UTC with "Z", as used by the API (conventions: Identifiers and time). */
    public static function rfc3339(?\DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
