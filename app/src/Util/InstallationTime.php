<?php

declare(strict_types=1);

namespace App\Util;

/**
 * The installation's time zone (APP_TIMEZONE, an IANA name such as Africa/Johannesburg): the
 * dashboard shows times in it, the operator overview groups hours and days by it, and the
 * quota, usage and billing calendar days and months are its days and months. The database
 * stores UTC, and the API, webhooks and exports carry RFC 3339 UTC timestamps.
 */
final class InstallationTime
{
    public readonly \DateTimeZone $zone;

    public function __construct(string $timezone)
    {
        try {
            $this->zone = new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new \InvalidArgumentException(\sprintf('APP_TIMEZONE must be an IANA time zone name such as Africa/Johannesburg or UTC, not "%s".', $timezone));
        }
    }

    public function name(): string
    {
        return $this->zone->getName();
    }

    /** The zone's current abbreviation ("SAST"), or its offset where it has none ("+02"). */
    public function abbreviation(): string
    {
        return (new \DateTimeImmutable('now', $this->zone))->format('T');
    }

    /** A stored time (DateTime or database string) in this zone; null for none. */
    public function local(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $dt = $value instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($value) : new \DateTimeImmutable((string) $value);

        return $dt->setTimezone($this->zone);
    }
}
