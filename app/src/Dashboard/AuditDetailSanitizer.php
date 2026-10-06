<?php

declare(strict_types=1);

namespace App\Dashboard;

/**
 * Presentation-time sanitiser for audit_log.detail_json. Audit writers never
 * record secrets, but the dashboard does not render stored metadata blindly:
 * values under secret-like keys are replaced, long strings are shortened and
 * nesting is bounded. The stored row is never changed.
 */
final class AuditDetailSanitizer
{
    private const SECRET_KEY = '/(secret|password|passwd|token|hash|cipher|credential|authorization|api_key|private|signature)/i';
    private const MAX_STRING = 300;
    private const MAX_ITEMS = 50;
    private const MAX_DEPTH = 4;

    /**
     * @param array<mixed> $detail
     *
     * @return array<mixed>
     */
    public static function sanitize(array $detail, int $depth = 0): array
    {
        $out = [];
        $n = 0;
        foreach ($detail as $key => $value) {
            if (++$n > self::MAX_ITEMS) {
                $out['…'] = '(more items not shown)';
                break;
            }
            if (\is_string($key) && 1 === preg_match(self::SECRET_KEY, $key)) {
                $out[$key] = '[redacted]';
            } elseif (\is_array($value)) {
                $out[$key] = $depth >= self::MAX_DEPTH ? '[nested data not shown]' : self::sanitize($value, $depth + 1);
            } elseif (\is_string($value) && mb_strlen($value) > self::MAX_STRING) {
                $out[$key] = mb_substr($value, 0, self::MAX_STRING).'…';
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
