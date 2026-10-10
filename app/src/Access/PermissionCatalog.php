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
        ['key' => 'SYSTEM.DELIVERY.CONTROL', 'group' => 'System · Delivery', 'description' => 'Activate or deactivate live delivery and stop or resume all outbound mail (Mail flow › Delivery and Stop sending, or smarthostctl prod live-enable/live-disable/pause/resume).'],
        ['key' => 'SYSTEM.SETUP.MANAGE', 'group' => 'System · Setup and diagnostics', 'description' => 'Use the administrator setup wizard: mark steps done, run the seed and bounce tests, send a test webhook.'],
        ['key' => 'SYSTEM.DIAGNOSTICS.RUN', 'group' => 'System · Setup and diagnostics', 'description' => 'Run the system checks and ask the host agent for backups, restore rehearsals, DKIM keys and TLS renewal.'],
        ['key' => 'SYSTEM.ADDRESS_BATCH.MANAGE', 'group' => 'System · Address batches', 'description' => 'Upload, validate, review and send address batches (up to 10,000 addresses) on behalf of a client, including re-permission sends.'],
        ['key' => 'PLATFORM.OVERVIEW.VIEW', 'group' => 'Platform', 'description' => 'Open the system overview (work queues, worker health, rates).'],
        ['key' => 'PLATFORM.SYSTEM.VIEW', 'group' => 'Platform', 'description' => 'View system health, the diagnostics and their history, and the host-agent requests.'],
        ['key' => 'PLATFORM.HELP.VIEW', 'group' => 'Platform', 'description' => 'Read the help and tutorials.'],
        ['key' => 'PLATFORM.CLIENT.VIEW', 'group' => 'Platform · Clients', 'description' => 'View every client and open any client dashboard read-only.'],
        ['key' => 'PLATFORM.CLIENT.MANAGE', 'group' => 'Platform · Clients', 'description' => 'Edit client account details, contacts, notes and the policy requirement, record policy acceptances, change capabilities; act in any client dashboard.'],
        ['key' => 'PLATFORM.CLIENT.APPROVE', 'group' => 'Platform · Clients', 'description' => 'Create client records, approve them, reactivate suspended clients and close clients (final).'],
        ['key' => 'PLATFORM.CLIENT.RESTRICT', 'group' => 'Platform · Clients', 'description' => 'Throttle or suspend a client and lift a throttle: the per-client kill switch.'],
        ['key' => 'PLATFORM.CLIENT_LIMIT.MANAGE', 'group' => 'Platform · Clients', 'description' => 'Set per-client limits and quotas (never above the installation ceilings).'],
        ['key' => 'PLATFORM.CLIENT_KEY.MANAGE', 'group' => 'Platform · Clients', 'description' => 'Create and revoke any client\'s API keys.'],
        ['key' => 'PLATFORM.USAGE.VIEW', 'group' => 'Platform · Usage and billing', 'description' => 'View usage of every client, reconciliation results and billing statements.'],
        ['key' => 'PLATFORM.USAGE.EXPORT', 'group' => 'Platform · Usage and billing', 'description' => 'Export usage and prepare, finalize, export or void billing statements.'],
        ['key' => 'PLATFORM.ABUSE.VIEW', 'group' => 'Platform · Abuse monitoring', 'description' => 'View reputation metrics and alerts of every client, and run the evaluation.'],
        ['key' => 'PLATFORM.ABUSE.MANAGE', 'group' => 'Platform · Abuse monitoring', 'description' => 'Acknowledge reputation alerts with a note.'],
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
