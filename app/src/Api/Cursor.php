<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

/**
 * Opaque keyset-pagination cursor (`limit` + `cursor`, OpenAPI). The cursor is
 * base64url JSON holding the last key of the previous page; it only moves the
 * starting point of an already tenant-scoped query, so tampering cannot widen
 * access. A malformed cursor or limit is a 400.
 */
final class Cursor
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 1000;

    public static function limit(Request $request): int
    {
        $raw = $request->query->get('limit');
        if (null === $raw) {
            return self::DEFAULT_LIMIT;
        }
        if (!\is_string($raw) || 1 !== preg_match('/^[0-9]{1,4}$/', $raw) || (int) $raw < 1 || (int) $raw > self::MAX_LIMIT) {
            throw ApiProblem::badRequest('limit must be an integer between 1 and 1000.', 'invalid-query-parameter');
        }

        return (int) $raw;
    }

    /** @return list<string>|null the key parts of the last row of the previous page */
    public static function decode(Request $request, int $parts): ?array
    {
        $raw = $request->query->get('cursor');
        if (null === $raw) {
            return null;
        }
        $json = \is_string($raw) && \strlen($raw) <= 512 ? base64_decode(strtr($raw, '-_', '+/'), true) : false;
        $key = false === $json ? null : json_decode($json, true);
        if (!\is_array($key) || \count($key) !== $parts || !array_is_list($key) || array_filter($key, 'is_string') !== $key) {
            throw ApiProblem::badRequest('cursor is not valid.', 'invalid-cursor');
        }

        return $key;
    }

    /** @param list<string> $key */
    public static function encode(array $key): string
    {
        return rtrim(strtr(base64_encode(json_encode($key, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }
}
