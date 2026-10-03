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
    }

    private static function read(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return false === $value || null === $value || '' === $value ? null : (string) $value;
    }
}
