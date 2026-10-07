<?php

declare(strict_types=1);

namespace App\Reputation;

use App\Enum\ClientAlertMetric;
use App\Enum\ClientAlertSeverity;

/**
 * Operator warning thresholds of the reputation evaluation (APP_REPUTATION_*, Phase 9).
 * Rates are percentages of the messages submitted in the window; the volume increase is
 * a factor over the previous daily average. A rate alert needs at least minMessages
 * messages submitted in the window.
 */
final class ReputationThresholds
{
    /** @param array<string, array{warning: float, critical: float}> $limits metric => thresholds */
    public function __construct(public readonly int $minMessages, private readonly array $limits)
    {
        if ($minMessages < 1) {
            throw new \InvalidArgumentException('APP_REPUTATION_MIN_MESSAGES must be at least 1.');
        }
        foreach (ClientAlertMetric::cases() as $metric) {
            $t = $limits[$metric->value] ?? throw new \InvalidArgumentException("No thresholds for {$metric->value}.");
            if ($t['warning'] <= 0 || $t['critical'] < $t['warning']) {
                throw new \InvalidArgumentException("The {$metric->value} thresholds must be positive and the critical one at least the warning one.");
            }
        }
    }

    /** @param array{minMessages: int, hardBounceWarning: float, hardBounceCritical: float, complaintWarning: float, complaintCritical: float, deferralWarning: float, deferralCritical: float, volumeWarning: float, volumeCritical: float} $c */
    public static function fromConfiguration(array $c): self
    {
        return new self((int) $c['minMessages'], [
            'hard_bounce_rate' => ['warning' => (float) $c['hardBounceWarning'], 'critical' => (float) $c['hardBounceCritical']],
            'complaint_rate' => ['warning' => (float) $c['complaintWarning'], 'critical' => (float) $c['complaintCritical']],
            'deferral_rate' => ['warning' => (float) $c['deferralWarning'], 'critical' => (float) $c['deferralCritical']],
            'volume_increase' => ['warning' => (float) $c['volumeWarning'], 'critical' => (float) $c['volumeCritical']],
        ]);
    }

    /** @return array{warning: float, critical: float} */
    public function of(ClientAlertMetric $metric): array
    {
        return $this->limits[$metric->value];
    }

    public function severity(ClientAlertMetric $metric, float $value): ?ClientAlertSeverity
    {
        $t = $this->limits[$metric->value];

        return match (true) {
            $value >= $t['critical'] => ClientAlertSeverity::Critical,
            $value >= $t['warning'] => ClientAlertSeverity::Warning,
            default => null,
        };
    }
}
