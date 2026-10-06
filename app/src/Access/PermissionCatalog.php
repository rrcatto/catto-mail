<?php

declare(strict_types=1);

namespace App\Access;

/**
 * The canonical list of secured actions of the dashboard (resource-first,
 * action-last, upper-case dot notation). Roles grant subsets of these keys
 * (role_permissions); ADMIN holds every key by definition. Code asks for a key
 * (#[IsGranted('PLATFORM.CLIENT.MANAGE')], is_granted() in Twig), never for a
 * role name. A key that is not listed here is never granted (PermissionVoter).
 *
 * SYSTEM.* keys are reserved to ADMIN: no other role can be given them.
 *
 * Client-scoped access (a user's own clients) is not in this catalogue: it comes
 * from client_memberships (viewer, member, admin) and App\Security\ClientVoter.
 */
final class PermissionCatalog
{
    /** @var list<array{key: string, group: string, description: string}> */
    private const PERMISSIONS = [
        ['key' => 'SYSTEM.ROLE.VIEW', 'group' => 'System · Access control', 'description' => 'View roles and the permissions they grant.'],
        ['key' => 'SYSTEM.ROLE.MANAGE', 'group' => 'System · Access control', 'description' => 'Create, edit and delete roles, change their permissions, and grant or revoke ADMIN.'],
        ['key' => 'PLATFORM.OVERVIEW.VIEW', 'group' => 'Platform', 'description' => 'Open the system overview (work queues, worker health, rates).'],
        ['key' => 'PLATFORM.CLIENT.VIEW', 'group' => 'Platform · Clients', 'description' => 'View every client and open any client dashboard read-only.'],
        ['key' => 'PLATFORM.CLIENT.MANAGE', 'group' => 'Platform · Clients', 'description' => 'Change client status and capabilities; act in any client dashboard.'],
        ['key' => 'PLATFORM.USER.VIEW', 'group' => 'Platform · Users', 'description' => 'View dashboard users, their roles and client memberships.'],
        ['key' => 'PLATFORM.USER.MANAGE', 'group' => 'Platform · Users', 'description' => 'Create, enable and disable users, send sign-in links, manage memberships and grant non-ADMIN roles.'],
        ['key' => 'PLATFORM.DSN.VIEW', 'group' => 'Platform · Unmatched DSNs', 'description' => 'View unmatched DSNs and their candidates.'],
        ['key' => 'PLATFORM.DSN.MANAGE', 'group' => 'Platform · Unmatched DSNs', 'description' => 'Request matches and dismiss unmatched DSNs.'],
        ['key' => 'PLATFORM.SUPPRESSION.VIEW', 'group' => 'Platform · Suppressions', 'description' => 'View every suppression with its provenance.'],
        ['key' => 'PLATFORM.SUPPRESSION.MANAGE', 'group' => 'Platform · Suppressions', 'description' => 'Create operator blocks and lift suppressions.'],
        ['key' => 'PLATFORM.AUDIT.VIEW', 'group' => 'Platform · Audit', 'description' => 'View the audit log.'],
        ['key' => 'PLATFORM.WEBHOOK.VIEW', 'group' => 'Platform · Webhooks', 'description' => 'View the webhook outbox.'],
    ];

    /** @return list<array{key: string, group: string, description: string}> */
    public static function all(): array
    {
        return self::PERMISSIONS;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::PERMISSIONS, 'key');
    }

    public static function exists(string $key): bool
    {
        return \in_array($key, self::keys(), true);
    }

    /** Keys a role other than ADMIN may hold. */
    public static function isAssignableToCustomRoles(string $key): bool
    {
        return self::exists($key) && !str_starts_with($key, 'SYSTEM.');
    }

    /** @return array<string, list<array{key: string, group: string, description: string}>> */
    public static function grouped(bool $includeSystem): array
    {
        $out = [];
        foreach (self::PERMISSIONS as $p) {
            if ($includeSystem || !str_starts_with($p['key'], 'SYSTEM.')) {
                $out[$p['group']][] = $p;
            }
        }

        return $out;
    }
}
