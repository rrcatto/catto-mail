<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Startup safety checks from docs/contracts/environment.md (rule 4, fail closed).
 *
 * Runs on every kernel boot (web and console). Only the safety-relevant shared
 * variables are checked here; every other variable is resolved lazily by the
 * container and a missing one fails when first used.
 */
final class SafetyGuard
{
    public const ENVIRONMENTS = ['development', 'test', 'production'];

    public static function assertSafe(): void
    {
        $env = self::read('SMARTHOST_ENV');
        if (null === $env || !\in_array($env, self::ENVIRONMENTS, true)) {
            throw new \RuntimeException('SMARTHOST_ENV must be one of: '.implode(', ', self::ENVIRONMENTS).'.');
        }
        $allow = self::read('SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS') ?? 'false';
        if (!\in_array($allow, ['true', 'false'], true)) {
            throw new \RuntimeException('SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS must be "true" or "false".');
        }
        if ('true' === $allow && 'production' === $env) {
            throw new \RuntimeException('SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS=true is forbidden with SMARTHOST_ENV=production.');
        }
        if ('production' === $env) {
            self::assertProduction();
        }
    }

    /**
     * Phase 8 (specification 2.9): a production process never runs the debug kernel,
     * never builds links from a non-https origin and never has the development
     * webhook private-host allowlist. The full production rules are checked before
     * deployment by `smarthostctl prod check`; these are the ones a misconfigured
     * container would otherwise run with.
     */
    private static function assertProduction(): void
    {
        // The debug kernel never runs in production (the deployment rules require APP_ENV=prod;
        // the test kernel may simulate production in the test suite).
        if ('dev' === self::read('APP_ENV')) {
            throw new \RuntimeException('SMARTHOST_ENV=production never runs the debug kernel (APP_ENV=dev); use APP_ENV=prod.');
        }
        $base = self::read('SMARTHOST_PUBLIC_BASE_URL');
        if (null !== $base && 'https' !== parse_url($base, \PHP_URL_SCHEME)) {
            throw new \RuntimeException('SMARTHOST_PUBLIC_BASE_URL must be https in production.');
        }
        if (null !== self::read('APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS')) {
            throw new \RuntimeException('APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS must be empty in production (SSRF policy).');
        }
    }

    private static function read(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return false === $value || null === $value || '' === $value ? null : (string) $value;
    }
}
