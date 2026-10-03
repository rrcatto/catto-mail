<?php

declare(strict_types=1);

namespace App\Sending;

/**
 * D-18 address normalisation - the one rule shared by PHP, Python and Go
 * (docs/architecture/conventions.md "Email addresses"):
 *
 *  1. trim surrounding ASCII whitespace (SP, HT, LF, VT, FF, CR);
 *  2. split at the LAST "@"; both parts must be non-empty;
 *  3. preserve the local part byte-for-byte (never case-folded);
 *  4. domain: an ASCII domain is lower-cased (ASCII only); a domain containing
 *     non-ASCII characters is converted to its A-label form with UTS #46
 *     non-transitional processing (which also lower-cases); a domain that
 *     cannot be converted has no normalised form.
 *
 * Used for duplicate-recipient detection and suppression matching. It does not
 * judge deliverability (that is the validator's job).
 */
final class AddressNormalizer
{
    private const WHITESPACE = " \t\n\x0B\x0C\r";

    public static function normalize(string $address): ?string
    {
        $trimmed = trim($address, self::WHITESPACE);
        $at = strrpos($trimmed, '@');
        if (false === $at || 0 === $at || $at === \strlen($trimmed) - 1) {
            return null;
        }
        $domain = self::normalizeDomain(substr($trimmed, $at + 1));

        return null === $domain ? null : substr($trimmed, 0, $at).'@'.$domain;
    }

    public static function normalizeDomain(string $domain): ?string
    {
        if ('' === $domain) {
            return null;
        }
        if (1 === preg_match('/^[\x00-\x7F]*$/', $domain)) {
            return strtolower($domain);
        }
        $ascii = idn_to_ascii($domain, \IDNA_NONTRANSITIONAL_TO_ASCII | \IDNA_CHECK_BIDI | \IDNA_CHECK_CONTEXTJ, \INTL_IDNA_VARIANT_UTS46, $info);

        return false === $ascii || '' === $ascii ? null : strtolower($ascii);
    }

    /** Domain part of a normalised address. */
    public static function domainOf(string $normalized): string
    {
        return substr($normalized, strrpos($normalized, '@') + 1);
    }

    /**
     * Structural sanity for addresses that Smarthost will put into SMTP envelopes
     * and headers: a host-name domain with at least two labels, a local part of at
     * most 64 octets without control characters or unquoted whitespace.
     */
    public static function isDeliverableSyntax(string $normalized): bool
    {
        $at = strrpos($normalized, '@');
        $local = substr($normalized, 0, $at);
        $domain = substr($normalized, $at + 1);
        if (\strlen($local) > 64 || 1 === preg_match('/[\x00-\x20\x7F]/', $local)) {
            return false;
        }

        return self::isHostname($domain) && str_contains($domain, '.');
    }

    public static function isHostname(string $domain): bool
    {
        if (\strlen($domain) > 253) {
            return false;
        }
        foreach (explode('.', $domain) as $label) {
            if (1 !== preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }
}
