<?php

declare(strict_types=1);

namespace App\Dashboard;

/**
 * The period an overview page reports on (?period=24h|7d|30d|90d). Periods are whole
 * buckets in the dashboard's time zone (InstallationTime) that end with the current one: 24
 * hours in hourly buckets, otherwise whole days including today. The previous period has
 * the same length and ends where this one starts; the figures compare against it.
 */
final class OverviewPeriod
{
    public const DEFAULT = '7d';

    /** key => [label, bucket unit, bucket count] */
    public const PERIODS = [
        '24h' => ['24 hours', 'hour', 24],
        '7d' => ['7 days', 'day', 7],
        '30d' => ['30 days', 'day', 30],
        '90d' => ['90 days', 'day', 90],
    ];

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $unit,
        public readonly int $buckets,
        public readonly \DateTimeImmutable $since,
        public readonly \DateTimeImmutable $previousSince,
        public readonly \DateTimeZone $zone,
    ) {
    }

    public static function fromKey(string $key, \DateTimeZone $zone, ?\DateTimeImmutable $now = null): self
    {
        if (!isset(self::PERIODS[$key])) {
            $key = self::DEFAULT;
        }
        [$label, $unit, $buckets] = self::PERIODS[$key];
        $now = ($now ?? new \DateTimeImmutable())->setTimezone($zone);
        $current = 'hour' === $unit ? $now->setTime((int) $now->format('G'), 0) : $now->setTime(0, 0);
        $since = $current->modify(\sprintf('-%d %s', $buckets - 1, $unit));

        return new self($key, $label, $unit, $buckets, $since, $since->modify(\sprintf('-%d %s', $buckets, $unit)), $zone);
    }

    /** The zone's abbreviation at the start of the period ("SAST"). */
    public function zoneAbbreviation(): string
    {
        return $this->since->format('T');
    }

    /**
     * The bucket starts of this period, oldest first, as 'Y-m-d H:00' in the period's zone.
     *
     * @return list<string>
     */
    public function bucketKeys(): array
    {
        $keys = [];
        for ($i = 0; $i < $this->buckets; ++$i) {
            $keys[] = $this->since->modify(\sprintf('+%d %s', $i, $this->unit))->format('Y-m-d H:00');
        }

        return $keys;
    }

    /** A short label for a bucket key: '14:00' for hours, '7 Oct' for days. */
    public function bucketLabel(string $key): string
    {
        $t = new \DateTimeImmutable($key, $this->zone);

        return 'hour' === $this->unit ? $t->format('H:i') : $t->format('j M');
    }
}
