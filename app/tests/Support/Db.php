<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Direct database connections for assertions and fixtures that the application
 * role cannot (and must not) perform. Independent of the kernel's connections.
 */
final class Db
{
    /** @var array<string, Connection> */
    private static array $connections = [];

    public static function owner(?string $database = null): Connection
    {
        return self::connect(self::env('SMARTHOST_DB_OWNER_USER'), self::env('SMARTHOST_DB_OWNER_PASSWORD'), $database);
    }

    public static function app(?string $database = null): Connection
    {
        return self::connect(self::env('APP_DB_USER'), self::env('APP_DB_PASSWORD'), $database);
    }

    /** A connection that is never shared (concurrency tests). */
    public static function newApp(): Connection
    {
        return DriverManager::getConnection(self::params(self::env('APP_DB_USER'), self::env('APP_DB_PASSWORD'), null));
    }

    public static function databaseName(): string
    {
        return self::env('SMARTHOST_DB_NAME');
    }

    public static function env(string $name): string
    {
        $value = $_SERVER[$name] ?? getenv($name);
        if (false === $value || '' === $value) {
            throw new \RuntimeException("Test environment variable $name is not set (run infra/tests/phase2-test.sh).");
        }

        return (string) $value;
    }

    private static function connect(string $user, string $password, ?string $database): Connection
    {
        $key = $user.'@'.($database ?? self::databaseName());

        return self::$connections[$key] ??= DriverManager::getConnection(self::params($user, $password, $database));
    }

    /** @return array<string, mixed> */
    private static function params(string $user, string $password, ?string $database): array
    {
        return ['driver' => 'pdo_pgsql', 'host' => self::env('SMARTHOST_DB_HOST'), 'port' => (int) self::env('SMARTHOST_DB_PORT'),
            'dbname' => $database ?? self::databaseName(), 'user' => $user, 'password' => $password, 'serverVersion' => '16'];
    }
}
