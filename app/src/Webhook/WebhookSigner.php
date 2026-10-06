<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * The webhook signature (D-10; OpenAPI `webhooks.smarthostEvent`; conventions):
 *
 *   Smarthost-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<body>")>[,v1=<hex>]
 *
 * <body> is the exact request-body bytes sent. One v1 value per active secret:
 * the current one, plus the previous one while its rotation overlap lasts.
 * Receivers accept the request when any v1 matches (constant-time comparison)
 * and the timestamp is within the tolerance (5 minutes).
 */
final class WebhookSigner
{
    public const TOLERANCE_SECONDS = 300;

    /** @param list<string> $secrets */
    public static function header(string $body, array $secrets, int $timestamp): string
    {
        $parts = ['t='.$timestamp];
        foreach ($secrets as $secret) {
            $parts[] = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        }

        return implode(',', $parts);
    }

    /**
     * Receiver-side verification (tests, fixtures, documentation of the contract).
     *
     * @param list<string> $secrets
     */
    public static function verify(string $body, string $header, array $secrets, int $now, int $tolerance = self::TOLERANCE_SECONDS): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $item) {
            [$k, $v] = array_pad(explode('=', trim($item), 2), 2, '');
            if ('t' === $k && 1 === preg_match('/^\d{1,12}$/', $v)) {
                $timestamp = (int) $v;
            } elseif ('v1' === $k && 1 === preg_match('/^[0-9a-f]{64}$/', $v)) {
                $signatures[] = $v;
            }
        }
        if (null === $timestamp || [] === $signatures || abs($now - $timestamp) > $tolerance) {
            return false;
        }
        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }
}
