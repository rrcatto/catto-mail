<?php

declare(strict_types=1);

namespace App\Dashboard;

use Symfony\Component\HttpFoundation\Request;

/**
 * One dashboard list request: a whitelisted sort, its direction, an opaque keyset
 * cursor and the page size. Nothing from the request ever becomes SQL text: the
 * sort name selects a predefined expression, values are bound parameters.
 *
 * Keyset (cursor) pagination: rows are ordered by (sort expression, id) and the
 * next page starts strictly after the last row's (value, id), so large tables are
 * never read with OFFSET and the order is stable under ties.
 */
final class Listing
{
    public const MAX_LIMIT = 200;

    /** @param list<string>|null $after [sort value, id] of the previous page's last row */
    private function __construct(
        public readonly string $sort,
        public readonly string $dir,
        public readonly ?array $after,
        public readonly int $limit,
    ) {
    }

    /** @param list<string> $sorts allowed sort names */
    public static function fromRequest(Request $request, array $sorts, string $defaultSort, string $defaultDir = 'desc', int $defaultLimit = 50): self
    {
        $sort = $request->query->getString('sort', $defaultSort);
        $sort = \in_array($sort, $sorts, true) ? $sort : $defaultSort;
        $dir = 'asc' === $request->query->getString('dir', $defaultDir) ? 'asc' : 'desc';
        $limit = max(1, min(self::MAX_LIMIT, $request->query->getInt('limit', $defaultLimit) ?: $defaultLimit));

        return new self($sort, $dir, self::decode($request->query->getString('cursor')), $limit);
    }

    public static function first(string $sort, string $dir, int $limit): self
    {
        return new self($sort, $dir, null, $limit);
    }

    public function withAfter(?array $after): self
    {
        return new self($this->sort, $this->dir, $after, $this->limit);
    }

    /** @param list<string> $values */
    public static function encode(array $values): string
    {
        return rtrim(strtr(base64_encode(json_encode($values, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return list<string>|null */
    private static function decode(string $cursor): ?array
    {
        if ('' === $cursor || \strlen($cursor) > 512) {
            return null;
        }
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $values = false === $json ? null : json_decode($json, true);
        if (!\is_array($values) || 2 !== \count($values) || !array_is_list($values)) {
            return null;
        }
        foreach ($values as $v) {
            if (!\is_string($v) || \strlen($v) > 400) {
                return null;
            }
        }

        return $values;
    }
}
