<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Util\InstallationTime;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/** Small presentation helpers for the dashboard templates. */
final class DashboardTwigExtension
{
    /** @var array<string, mixed>|null */
    private ?array $deliveryStatus = null;

    /** @var array<string, array<string, mixed>> */
    private array $navigation = [];

    public function __construct(
        private readonly SecurityHeadersSubscriber $headers,
        private readonly \App\System\DeliveryControl $delivery,
        private readonly Navigation $nav,
        private readonly InstallationTime $time,
    ) {
    }

    /** The dashboard's time zone name (APP_TIMEZONE), for the date filter and the charts. */
    #[AsTwigFunction('time_zone')]
    public function timeZone(): string
    {
        return $this->time->name();
    }

    /** "SAST (Africa/Johannesburg)": how the pages say which time zone their times are in. */
    #[AsTwigFunction('time_zone_label')]
    public function timeZoneLabel(): string
    {
        $abbr = $this->time->abbreviation();

        return $abbr === $this->time->name() ? $abbr : $abbr.' ('.$this->time->name().')';
    }

    #[AsTwigFunction('time_zone_abbreviation')]
    public function timeZoneAbbreviation(): string
    {
        return $this->time->abbreviation();
    }

    /**
     * The area buttons and page pills for the current page (Navigation), built once per request.
     *
     * @return array<string, mixed>
     */
    #[AsTwigFunction('navigation')]
    public function navigation(?\App\Entity\Client $client, string $section): array
    {
        $key = ($client?->getId()->toRfc4122() ?? '').'|'.$section;

        return $this->navigation[$key] ??= $this->nav->build($client, $section);
    }

    #[AsTwigFunction('app_version')]
    public static function appVersion(): string
    {
        return \App\Version::VERSION;
    }

    /** "12 s ago" style ages of a timestamp; "never" for none. */
    #[AsTwigFilter('ago')]
    public static function ago(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return 'never';
        }
        $dt = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value);

        return \App\System\DeliveryControl::duration(max(0, time() - $dt->getTimestamp())).' ago';
    }

    /** Up to two initials of a name, for the round name badges. */
    #[AsTwigFilter('initials')]
    public static function initials(mixed $name): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', (string) $name, -1, \PREG_SPLIT_NO_EMPTY) ?: ['?'];
        $first = mb_substr($words[0], 0, 1);

        return mb_strtoupper(\count($words) > 1 ? $first.mb_substr($words[1], 0, 1) : mb_substr($words[0], 0, 2));
    }

    /** A stable colour slot (0-5) for a name badge. */
    #[AsTwigFilter('tone_slot')]
    public static function toneSlot(mixed $name): int
    {
        return crc32((string) $name) % 6;
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

    /** Consistent timestamps in the dashboard's time zone, ISO-like, seconds precision, with the zone; "—" for none. */
    #[AsTwigFilter('ts')]
    public function timestamp(mixed $value): string
    {
        return $this->time->local($value)?->format('Y-m-d H:i:s T') ?? '—';
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
