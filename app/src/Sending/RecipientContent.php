<?php

declare(strict_types=1);

namespace App\Sending;

/**
 * Size and fingerprint of a recipient's rendered content, kept after the D-14
 * purge (send_job_recipients.content_bytes / content_sha256).
 *
 *   content_bytes  = octets of subject + html_body + text_body (absent part = 0)
 *   content_sha256 = SHA-256 (lower-case hex) of the JSON array
 *                    [subject, html_body|null, text_body|null] encoded without
 *                    escaped slashes or Unicode.
 */
final class RecipientContent
{
    public static function bytes(string $subject, ?string $html, ?string $text): int
    {
        return \strlen($subject) + \strlen($html ?? '') + \strlen($text ?? '');
    }

    public static function sha256(string $subject, ?string $html, ?string $text): string
    {
        return hash('sha256', json_encode([$subject, $html, $text], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }
}
