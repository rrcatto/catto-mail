<?php

declare(strict_types=1);

namespace App\System;

/**
 * Removes secrets from anything the host agent or a check reports before it is stored
 * (specification 2.11: test output never holds secrets). Belt and braces: the agent
 * already sends only sanitised summaries.
 */
final class Redactor
{
    private const PATTERNS = [
        '/shk_[A-Za-z0-9_-]{20,}/' => 'shk_[redacted]',                                         // API keys
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s' => '[private key redacted]',
        '/((?:password|passwd|secret|token|api[_-]?key|passphrase)["\']?\s*[:=]\s*["\']?)[^\s"\',;&]+/i' => '$1[redacted]',
        '#(/(?:t/[oc]|p|dashboard/login/verify\?token=)/?)[A-Za-z0-9_-]{20,}#' => '$1[token]',  // tracking, re-permission, sign-in
        '/(postgres(?:ql)?:\/\/[^:\s]+:)[^@\s]+@/' => '$1[redacted]@',
    ];

    public static function text(string $text): string
    {
        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function data(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (\is_string($key) && 1 === preg_match('/password|secret|private_key|passphrase|^token$|api_key/i', $key)) {
                $out[$key] = '[redacted]';
            } elseif (\is_array($value)) {
                $out[$key] = $depth < 8 ? self::data($value, $depth + 1) : '[truncated]';
            } elseif (\is_string($value)) {
                $out[$key] = mb_substr(self::text($value), 0, 4000);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
