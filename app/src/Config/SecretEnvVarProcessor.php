<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * `%env(secret:NAME)%`: the contract's `_FILE` indirection (environment.md rule 2).
 *
 * A secret NAME may be given directly or as NAME_FILE (a mounted file, e.g. a
 * Podman secret). Setting both is a startup error; a single trailing newline of a
 * file is removed.
 */
final class SecretEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $direct = self::read($name);
        $file = self::read($name.'_FILE');
        if (null !== $direct && null !== $file) {
            throw new RuntimeException(\sprintf('Both %s and %s_FILE are set; set only one.', $name, $name));
        }
        if (null !== $file) {
            $contents = @file_get_contents($file);
            if (false === $contents) {
                throw new RuntimeException(\sprintf('%s_FILE points to an unreadable file.', $name));
            }

            return rtrim($contents, "\r\n");
        }
        if (null === $direct) {
            throw new EnvNotFoundException(\sprintf('Environment variable not found: "%s" (or %s_FILE).', $name, $name));
        }

        return $direct;
    }

    public static function getProvidedTypes(): array
    {
        return ['secret' => 'string'];
    }

    private static function read(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return false === $value || null === $value || '' === $value ? null : (string) $value;
    }
}
