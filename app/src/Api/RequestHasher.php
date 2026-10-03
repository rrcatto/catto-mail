<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Canonical request hash for idempotency (stored as `request_hash`):
 * SHA-256 (lower-case hex) of the decoded body re-encoded with object keys sorted
 * recursively, array order preserved, no insignificant whitespace, unescaped
 * slashes and Unicode. Whitespace and key order therefore do not matter; any
 * change of a value (including adding a field with its default) does.
 */
final class RequestHasher
{
    public static function hash(mixed $decoded): string
    {
        return hash('sha256', json_encode(self::canonical($decoded),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $props = get_object_vars($value);
            ksort($props, \SORT_STRING);

            return (object) array_map(self::canonical(...), $props);
        }
        if (\is_array($value)) {
            return array_map(self::canonical(...), $value);
        }

        return $value;
    }
}
