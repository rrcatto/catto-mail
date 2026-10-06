<?php

declare(strict_types=1);

namespace App\Access;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\Role;
use App\Entity\RolePermission;
use App\Entity\User;
use App\Entity\UserRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The access-control list: which roles a user holds, which permission keys they
 * grant, and the audited changes to both.
 *
 * Rules:
 *  - ADMIN holds every permission of PermissionCatalog; its permissions cannot be
 *    reduced and only an ADMIN may grant or revoke ADMIN;
 *  - the account whose email equals APP_ADMIN_EMAIL is given ADMIN whenever it
 *    signs in (and the ADMIN row is recreated if it was lost), so the configured
 *    administrator can always get back in;
 *  - SYSTEM.* permissions belong to ADMIN alone;
 *  - nobody can revoke their own ADMIN, and the last ADMIN cannot be revoked;
 *  - built-in roles cannot be deleted; a custom role can only be deleted when no
 *    user holds it.
 */
final class AccessControl
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly AuditLogger $audit,
        #[Autowire('%app.admin_email%')] private readonly string $adminEmail,
    ) {
    }

    public function isConfiguredAdmin(string $email): bool
    {
        $configured = strtolower(trim($this->adminEmail));

        return '' !== $configured && strtolower(trim($email)) === $configured;
    }

    /** Loads the user's role keys and permission keys onto the (transient) user. */
    public function resolve(User $user): User
    {
        $roles = $this->roleKeys($user);
        $permissions = \in_array(RoleCatalog::ADMIN, $roles, true)
            ? PermissionCatalog::keys()
            : array_values(array_filter($this->connection->fetchFirstColumn(<<<'SQL'
                SELECT DISTINCT rp.permission_key FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id
                 WHERE ur.user_id = ? ORDER BY 1
                SQL, [$user->getId()->toRfc4122()]), static fn (string $k): bool => PermissionCatalog::isAssignableToCustomRoles($k)));
        $user->setAccess($roles, $permissions);

        return $user;
    }

    /** @return list<string> */
    public function roleKeys(User $user): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT r.role_key FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.role_key',
            [$user->getId()->toRfc4122()]);
    }

    /**
     * Gives ADMIN to the configured administrator (no-op for anyone else or when
     * already held). Called at every sign-in of that account. True when granted now.
     */
    public function bootstrapAdministrator(User $user): bool
    {
        if (!$this->isConfiguredAdmin($user->getEmail())) {
            return false;
        }
        $admin = $this->ensureRole(RoleCatalog::ADMIN, 'Administrator', 'Every permission, always; cannot be reduced.');
        if (null !== $this->userRole($user, $admin)) {
            return false;
        }
        $this->em->wrapInTransaction(function () use ($user, $admin): void {
            $this->em->persist(new UserRole($user, $admin, null));
            $this->em->flush();
            $this->audit->record(AuditActor::system('app-admin-email'), 'user.role_granted', 'user', $user->getId()->toRfc4122(),
                ['role' => RoleCatalog::ADMIN, 'reason' => 'APP_ADMIN_EMAIL']);
        });

        return true;
    }

    /** @return list<Role> by key */
    public function roles(): array
    {
        return $this->em->createQuery('SELECT r FROM App\Entity\Role r ORDER BY r.isBuiltin DESC, r.roleKey')->getResult();
    }

    public function role(string $id): ?Role
    {
        return Uuid::isValid($id) ? $this->em->find(Role::class, Uuid::fromString($id)) : null;
    }

    public function roleByKey(string $key): ?Role
    {
        return $this->em->getRepository(Role::class)->findOneBy(['roleKey' => $key]);
    }

    /** @return list<string> the permission keys a role grants (every key for ADMIN) */
    public function permissionsOfRole(Role $role): array
    {
        if (RoleCatalog::ADMIN === $role->getRoleKey()) {
            return PermissionCatalog::keys();
        }

        return $this->connection->fetchFirstColumn('SELECT permission_key FROM role_permissions WHERE role_id = ? ORDER BY 1',
            [$role->getId()->toRfc4122()]);
    }

    /** @return array<string, int> role id => number of users holding it */
    public function roleUserCounts(): array
    {
        return array_map('intval', $this->connection->fetchAllKeyValue('SELECT role_id::text, count(*) FROM user_roles GROUP BY role_id'));
    }

    public function grantRole(User $user, Role $role, User $actor): bool
    {
        $this->assertMayChangeRole($role, $actor);
        if (null !== $this->userRole($user, $role)) {
            return false;
        }

        return $this->em->wrapInTransaction(function () use ($user, $role, $actor): bool {
            $this->em->persist(new UserRole($user, $role, $actor));
            $this->em->flush();
            $this->audit->record(AuditActor::user($actor), 'user.role_granted', 'user', $user->getId()->toRfc4122(), ['role' => $role->getRoleKey()]);

            return true;
        });
    }

    public function revokeRole(User $user, Role $role, User $actor): bool
    {
        $this->assertMayChangeRole($role, $actor);
        $held = $this->userRole($user, $role);
        if (null === $held) {
            return false;
        }
        if (RoleCatalog::ADMIN === $role->getRoleKey()) {
            if ($user->getId()->equals($actor->getId())) {
                throw new DomainRuleViolation('You cannot revoke your own ADMIN role.');
            }
            if ($this->countHolders($role) <= 1) {
                throw new DomainRuleViolation('The last ADMIN cannot be revoked.');
            }
        }

        return $this->em->wrapInTransaction(function () use ($held, $user, $role, $actor): bool {
            $this->em->remove($held);
            $this->em->flush();
            $this->audit->record(AuditActor::user($actor), 'user.role_revoked', 'user', $user->getId()->toRfc4122(), ['role' => $role->getRoleKey()]);

            return true;
        });
    }

    /** Console path (trusted, audited with the given actor): grants a role by key. */
    public function grantRoleAsSystem(User $user, string $key, AuditActor $actor): bool
    {
        $role = $this->roleByKey(strtoupper(trim($key))) ?? throw new DomainRuleViolation("No role $key.");
        if (null !== $this->userRole($user, $role)) {
            return false;
        }

        return $this->em->wrapInTransaction(function () use ($user, $role, $actor): bool {
            $this->em->persist(new UserRole($user, $role, null));
            $this->em->flush();
            $this->audit->record($actor, 'user.role_granted', 'user', $user->getId()->toRfc4122(), ['role' => $role->getRoleKey()]);

            return true;
        });
    }

    /** Console path: revokes a role by key (the last ADMIN still cannot be revoked). */
    public function revokeRoleAsSystem(User $user, string $key, AuditActor $actor): bool
    {
        $role = $this->roleByKey(strtoupper(trim($key))) ?? throw new DomainRuleViolation("No role $key.");
        $held = $this->userRole($user, $role);
        if (null === $held) {
            return false;
        }
        if (RoleCatalog::ADMIN === $role->getRoleKey() && $this->countHolders($role) <= 1) {
            throw new DomainRuleViolation('The last ADMIN cannot be revoked.');
        }

        return $this->em->wrapInTransaction(function () use ($held, $user, $role, $actor): bool {
            $this->em->remove($held);
            $this->em->flush();
            $this->audit->record($actor, 'user.role_revoked', 'user', $user->getId()->toRfc4122(), ['role' => $role->getRoleKey()]);

            return true;
        });
    }

    public function createRole(string $key, string $name, string $description, User $actor): Role
    {
        $key = strtoupper(trim($key));
        $name = trim($name);
        if (1 !== preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $key)) {
            throw new DomainRuleViolation('A role key is 2 to 64 upper-case letters, digits or underscores, starting with a letter.');
        }
        if (RoleCatalog::isBuiltIn($key) || null !== $this->roleByKey($key)) {
            throw new DomainRuleViolation("A role $key already exists.");
        }
        if ('' === $name || mb_strlen($name) > 100) {
            throw new DomainRuleViolation('A role name is 1 to 100 characters.');
        }
        $role = new Role($key, $name, trim($description));

        return $this->em->wrapInTransaction(function () use ($role, $actor): Role {
            $this->em->persist($role);
            $this->em->flush();
            $this->audit->record(AuditActor::user($actor), 'acl.role_created', 'role', $role->getId()->toRfc4122(),
                ['role_key' => $role->getRoleKey(), 'name' => $role->getName()]);

            return $role;
        });
    }

    public function updateRole(Role $role, string $name, string $description, User $actor): void
    {
        if (RoleCatalog::ADMIN === $role->getRoleKey()) {
            throw new DomainRuleViolation('The ADMIN role cannot be changed.');
        }
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 100) {
            throw new DomainRuleViolation('A role name is 1 to 100 characters.');
        }
        if ($name === $role->getName() && trim($description) === $role->getDescription()) {
            return;
        }
        $this->em->wrapInTransaction(function () use ($role, $name, $description, $actor): void {
            $before = ['name' => $role->getName(), 'description' => $role->getDescription()];
            $role->rename($name, trim($description));
            $this->em->flush();
            $this->audit->record(AuditActor::user($actor), 'acl.role_updated', 'role', $role->getId()->toRfc4122(),
                ['role_key' => $role->getRoleKey(), 'before' => $before, 'after' => ['name' => $role->getName(), 'description' => $role->getDescription()]]);
        });
    }

    /**
     * Replaces the role's permissions with $keys (unknown and SYSTEM.* keys are
     * refused). True when something changed.
     *
     * @param list<string> $keys
     */
    public function setRolePermissions(Role $role, array $keys, User $actor): bool
    {
        if (RoleCatalog::ADMIN === $role->getRoleKey()) {
            throw new DomainRuleViolation('ADMIN always holds every permission; its permissions cannot be changed.');
        }
        $new = array_values(array_unique(array_map(static fn (string $k): string => strtoupper(trim($k)), $keys)));
        foreach ($new as $k) {
            if (!PermissionCatalog::isAssignableToCustomRoles($k)) {
                throw new DomainRuleViolation("$k cannot be granted to this role.");
            }
        }
        sort($new);
        $old = $this->permissionsOfRole($role);
        if ($old === $new) {
            return false;
        }
        $this->em->wrapInTransaction(function () use ($role, $old, $new, $actor): void {
            $this->connection->executeStatement('DELETE FROM role_permissions WHERE role_id = ?', [$role->getId()->toRfc4122()]);
            foreach ($new as $k) {
                $this->em->persist(new RolePermission($role, $k));
            }
            $this->em->flush();
            $this->audit->record(AuditActor::user($actor), 'acl.role_permissions_updated', 'role', $role->getId()->toRfc4122(),
                ['role_key' => $role->getRoleKey(), 'added' => array_values(array_diff($new, $old)), 'removed' => array_values(array_diff($old, $new))]);
        });

        return true;
    }

    public function deleteRole(Role $role, User $actor): void
    {
        if ($role->isBuiltin() || RoleCatalog::isBuiltIn($role->getRoleKey())) {
            throw new DomainRuleViolation('Built-in roles cannot be deleted.');
        }
        if ($this->countHolders($role) > 0) {
            throw new DomainRuleViolation('Revoke this role from every user before deleting it.');
        }
        $this->em->wrapInTransaction(function () use ($role, $actor): void {
            $id = $role->getId()->toRfc4122();
            $this->connection->executeStatement('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
            $this->em->remove($role);
            $this->em->flush();
            $this->audit->record(AuditActor::user($actor), 'acl.role_deleted', 'role', $id, ['role_key' => $role->getRoleKey()]);
        });
    }

    private function assertMayChangeRole(Role $role, User $actor): void
    {
        $this->resolve($actor);
        $needed = RoleCatalog::ADMIN === $role->getRoleKey() ? 'SYSTEM.ROLE.MANAGE' : 'PLATFORM.USER.MANAGE';
        if (!$actor->hasPermission($needed)) {
            throw new DomainRuleViolation(RoleCatalog::ADMIN === $role->getRoleKey()
                ? 'Only an administrator can grant or revoke ADMIN.' : 'You may not change user roles.');
        }
    }

    private function countHolders(Role $role): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM user_roles WHERE role_id = ?', [$role->getId()->toRfc4122()]);
    }

    private function userRole(User $user, Role $role): ?UserRole
    {
        return $this->em->getRepository(UserRole::class)->findOneBy(['user' => $user, 'role' => $role]);
    }

    /** Returns the role, recreating it when only its row was lost (a recovery path for ADMIN). */
    private function ensureRole(string $key, string $name, string $description): Role
    {
        $role = $this->roleByKey($key);
        if (null !== $role) {
            return $role;
        }
        $this->connection->executeStatement(
            'INSERT INTO roles (id, role_key, name, description, is_builtin) VALUES (?, ?, ?, ?, true) ON CONFLICT (role_key) DO NOTHING',
            [Uuid::v7()->toRfc4122(), $key, $name, $description]);

        return $this->roleByKey($key) ?? throw new \LogicException("Role $key could not be created.");
    }
}
