<?php

declare(strict_types=1);

namespace App\Dashboard;

use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/** Small presentation helpers for the dashboard templates. */
final class DashboardTwigExtension
{
    /** @var array<string, mixed>|null */
    private ?array $deliveryStatus = null;

    public function __construct(
        private readonly SecurityHeadersSubscriber $headers,
        private readonly \App\System\DeliveryControl $delivery,
    ) {
    }

    /**
     * The installation's delivery mode (HELD, LIVE, PAUSED, STOPPED) for the bar every
     * operator page shows (specification 2.11), read once per request.
     *
     * @return array<string, mixed>
     */
    #[AsTwigFunction('delivery_status')]
    public function deliveryStatus(): array
    {
        return $this->deliveryStatus ??= $this->delivery->status();
    }

    /** "3 min" style durations. */
    #[AsTwigFilter('duration')]
    public static function duration(mixed $seconds): string
    {
        return null === $seconds ? '—' : \App\System\DeliveryControl::duration((int) $seconds);
    }

    #[AsTwigFunction('csp_nonce')]
    public function cspNonce(): string
    {
        return $this->headers->nonce();
    }

    /** Consistent timestamps: UTC, ISO-like, seconds precision; "—" for none. */
    #[AsTwigFilter('ts')]
    public static function timestamp(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return '—';
        }
        $dt = $value instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($value) : new \DateTimeImmutable((string) $value);

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s').' UTC';
    }

    /** @return array<string, mixed> */
    #[AsTwigFilter('json_counts')]
    public static function jsonCounts(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        $decoded = \is_string($value) ? json_decode($value, true) : null;

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, string> */
    #[AsTwigFunction('label_options')]
    public static function labelOptions(string $vocabulary): array
    {
        return Labels::options($vocabulary);
    }

    #[AsTwigFilter('percent')]
    public static function percent(mixed $part, mixed $total): string
    {
        $t = (float) $total;

        return $t > 0 ? number_format(100 * (float) $part / $t, 1).' %' : '—';
    }
}
