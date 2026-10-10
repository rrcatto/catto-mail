<?php

declare(strict_types=1);

namespace App\Usage;

use App\Domain\DomainRuleViolation;
use App\Util\Clock;

/**
 * A bounded period for usage summaries, reconciliation, billing and export (Phase 9):
 * [start, end) of whole calendar days in the installation's time zone (SMARTHOST_TIMEZONE,
 * InstallationTime). Named periods are the current day, the current month and the
 * previous month; a custom period is at most 366 days long.
 */
final class UsagePeriod
{
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
        public readonly string $label,
        public readonly \DateTimeZone $zone,
    ) {
    }

    public static function named(string $name, \DateTimeZone $zone, ?\DateTimeImmutable $now = null): self
    {
        $now = ($now ?? Clock::now())->setTimezone($zone);
        $today = $now->setTime(0, 0);
        $month = $today->modify('first day of this month');

        return match ($name) {
            'current_day' => new self($today, $today->modify('+1 day'), 'current day', $zone),
            'current_month' => new self($month, $month->modify('+1 month'), 'current month', $zone),
            'previous_month' => new self($month->modify('-1 month'), $month, 'previous month', $zone),
            default => throw new DomainRuleViolation("Unknown period $name (current_day, current_month, previous_month, or a custom from/to)."),
        };
    }

    /** A calendar month such as 2026-09. */
    public static function month(string $yearMonth, \DateTimeZone $zone): self
    {
        if (1 !== preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $yearMonth)) {
            throw new DomainRuleViolation("$yearMonth is not a month (YYYY-MM).");
        }
        $start = new \DateTimeImmutable($yearMonth.'-01 00:00:00', $zone);

        return new self($start, $start->modify('+1 month'), $yearMonth, $zone);
    }

    /** A custom period of local dates, `to` exclusive. */
    public static function custom(string $from, string $to, \DateTimeZone $zone): self
    {
        $parse = static function (string $d) use ($zone): \DateTimeImmutable {
            $t = \DateTimeImmutable::createFromFormat('!Y-m-d', $d, $zone);
            if (false === $t || $t->format('Y-m-d') !== $d) {
                throw new DomainRuleViolation("$d is not a date (YYYY-MM-DD).");
            }

            return $t;
        };
        $start = $parse($from);
        $end = $parse($to);
        if ($end <= $start) {
            throw new DomainRuleViolation('The period end must be after its start.');
        }
        if ($start->diff($end)->days > self::MAX_DAYS) {
            throw new DomainRuleViolation(\sprintf('A custom period is at most %d days.', self::MAX_DAYS));
        }

        return new self($start, $end, "$from to $to (exclusive)", $zone);
    }

    public function startSql(): string
    {
        return $this->start->format('Y-m-d H:i:sP');
    }

    public function endSql(): string
    {
        return $this->end->format('Y-m-d H:i:sP');
    }

    /** @return array{start: string, end: string} RFC 3339 */
    public function asArray(): array
    {
        return ['start' => (string) Clock::rfc3339($this->start), 'end' => (string) Clock::rfc3339($this->end)];
    }
}
