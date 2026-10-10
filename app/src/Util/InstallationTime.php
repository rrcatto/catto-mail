<?php

declare(strict_types=1);

namespace App\Util;

/**
 * The installation's time zone (SMARTHOST_TIMEZONE, an IANA name; Africa/Johannesburg, SAST,
 * by default). Every time catto-mail shows, writes or computes is in it (owner decision): the
 * dashboard, API responses, webhook payloads, exports and logs carry its offset, the database
 * sessions use it, and the quota, usage and billing calendar days and months are its days and
 * months. The kernel makes it the PHP default zone on boot (applyProcessDefault).
 */
final class InstallationTime
{
    public const VARIABLE = 'SMARTHOST_TIMEZONE';

    public readonly \DateTimeZone $zone;

    public function __construct(string $timezone)
    {
        $this->zone = self::parse($timezone);
    }

    /** Make the configured zone the process's default (fail closed on a missing or unknown name). */
    public static function applyProcessDefault(): void
    {
        $value = $_SERVER[self::VARIABLE] ?? $_ENV[self::VARIABLE] ?? getenv(self::VARIABLE);
        date_default_timezone_set(self::parse(false === $value || null === $value ? '' : (string) $value)->getName());
    }

    private static function parse(string $timezone): \DateTimeZone
    {
        // An offset ("+02:00") or abbreviation ("SAST") is not a zone: it has no rules and no name.
        if (1 !== preg_match('~^[A-Za-z][A-Za-z0-9_+-]*(/[A-Za-z0-9_+-]+)*$~', $timezone)
            || !\in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \InvalidArgumentException(\sprintf('%s must be an IANA time zone name such as Africa/Johannesburg, not "%s".', self::VARIABLE, $timezone));
        }

        return new \DateTimeZone($timezone);
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
