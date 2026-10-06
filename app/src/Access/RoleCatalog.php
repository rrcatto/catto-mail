<?php

declare(strict_types=1);

namespace App\Access;

/**
 * Built-in installation-wide roles (rows seeded by the migration).
 *
 *   ADMIN     every permission, always; its permissions cannot be reduced; the
 *             account whose email is APP_ADMIN_EMAIL receives it at every sign-in
 *             (a recovery path that survives a revoked or deleted assignment);
 *   OPERATOR  runs the installation; seeded with every PLATFORM.* permission and
 *             editable like a custom role.
 *
 * Custom roles (upper-case keys) can be created in the dashboard.
 */
final class RoleCatalog
{
    public const ADMIN = 'ADMIN';
    public const OPERATOR = 'OPERATOR';
    public const BUILT_IN = [self::ADMIN, self::OPERATOR];

    public static function isBuiltIn(string $key): bool
    {
        return \in_array($key, self::BUILT_IN, true);
    }
}
