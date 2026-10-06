<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Passwordless dashboard sign-in with roles, permissions and an editable ACL.
 *
 *  - auth_login_tokens: single-use emailed sign-in links (only the SHA-256 of the
 *    token is stored), with the per-address and per-IP request history used for
 *    rate limiting;
 *  - roles, role_permissions, user_roles: installation-wide roles and the
 *    permission keys of App\Access\PermissionCatalog they grant. ADMIN holds every
 *    permission by definition (no rows needed); OPERATOR is seeded with every
 *    PLATFORM.* permission and can be edited like any custom role;
 *  - users.global_role is replaced by the OPERATOR role (existing operators keep
 *    their access), and users.password_hash is removed: there are no passwords.
 *
 * Client access stays in client_memberships (viewer/member/admin per client).
 * Reproduces docs/schema/reference-schema.sql (specification 2.7).
 */
final class Version20261006000200 extends AbstractMigration
{
    private const OPERATOR_PERMISSIONS = [
        'PLATFORM.OVERVIEW.VIEW', 'PLATFORM.CLIENT.VIEW', 'PLATFORM.CLIENT.MANAGE', 'PLATFORM.USER.VIEW', 'PLATFORM.USER.MANAGE',
        'PLATFORM.DSN.VIEW', 'PLATFORM.DSN.MANAGE', 'PLATFORM.SUPPRESSION.VIEW', 'PLATFORM.SUPPRESSION.MANAGE',
        'PLATFORM.AUDIT.VIEW', 'PLATFORM.WEBHOOK.VIEW',
    ];

    public function getDescription(): string
    {
        return 'Passwordless sign-in links, roles, role permissions and user roles; drop users.password_hash and users.global_role';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE roles (
    id           uuid        PRIMARY KEY,
    role_key     text        NOT NULL CHECK (role_key ~ '^[A-Z][A-Z0-9_]{1,63}$'),
    name         text        NOT NULL CHECK (length(name) BETWEEN 1 AND 100),
    description  text        NOT NULL DEFAULT '',
    is_builtin   boolean     NOT NULL DEFAULT false,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX roles_key_uq ON roles (role_key)');
        $this->addSql(<<<'SQL'
CREATE TABLE role_permissions (
    id              uuid        PRIMARY KEY,
    role_id         uuid        NOT NULL REFERENCES roles (id),
    permission_key  text        NOT NULL CHECK (permission_key ~ '^[A-Z][A-Z_]*(\.[A-Z][A-Z_]*)+$'),
    created_at      timestamptz NOT NULL DEFAULT now()
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX role_permissions_role_permission_uq ON role_permissions (role_id, permission_key)');
        $this->addSql(<<<'SQL'
CREATE TABLE user_roles (
    id           uuid        PRIMARY KEY,
    user_id      uuid        NOT NULL REFERENCES users (id),
    role_id      uuid        NOT NULL REFERENCES roles (id),
    assigned_by  uuid        NULL REFERENCES users (id),
    assigned_at  timestamptz NOT NULL DEFAULT now()
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX user_roles_user_role_uq ON user_roles (user_id, role_id)');
        $this->addSql('CREATE INDEX user_roles_role_idx ON user_roles (role_id)');
        $this->addSql(<<<'SQL'
CREATE TABLE auth_login_tokens (
    id                 uuid        PRIMARY KEY,
    email              text        NOT NULL,
    user_id            uuid        NULL REFERENCES users (id),
    token_hash         text        NOT NULL CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    return_path        text        NULL CHECK (return_path IS NULL OR return_path ~ '^/dashboard(/[^/]|$)'),
    requested_ip_hash  text        NOT NULL CHECK (requested_ip_hash ~ '^[0-9a-f]{64}$'),
    created_at         timestamptz NOT NULL DEFAULT now(),
    expires_at         timestamptz NOT NULL,
    used_at            timestamptz NULL,
    CONSTRAINT auth_login_tokens_expiry_after_creation CHECK (expires_at > created_at)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX auth_login_tokens_hash_uq ON auth_login_tokens (token_hash)');
        $this->addSql('CREATE INDEX auth_login_tokens_email_created_idx ON auth_login_tokens (lower(email), created_at)');
        $this->addSql('CREATE INDEX auth_login_tokens_ip_created_idx ON auth_login_tokens (requested_ip_hash, created_at)');
        $this->addSql('CREATE INDEX auth_login_tokens_user_idx ON auth_login_tokens (user_id) WHERE user_id IS NOT NULL');

        $admin = Uuid::v7()->toRfc4122();
        $operator = Uuid::v7()->toRfc4122();
        $this->addSql("INSERT INTO roles (id, role_key, name, description, is_builtin) VALUES (?, 'ADMIN', 'Administrator', 'Every permission, always; cannot be reduced.', true)", [$admin]);
        $this->addSql("INSERT INTO roles (id, role_key, name, description, is_builtin) VALUES (?, 'OPERATOR', 'Operator', 'Runs the installation: clients, users, DSNs, suppressions, audit and health.', true)", [$operator]);
        foreach (self::OPERATOR_PERMISSIONS as $key) {
            $this->addSql('INSERT INTO role_permissions (id, role_id, permission_key) VALUES (?, ?, ?)', [Uuid::v7()->toRfc4122(), $operator, $key]);
        }
        // Existing operators keep their access through the OPERATOR role.
        $this->addSql("INSERT INTO user_roles (id, user_id, role_id) SELECT gen_random_uuid(), id, ? FROM users WHERE global_role = 'operator'", [$operator]);
        $this->addSql('ALTER TABLE users DROP COLUMN global_role');
        $this->addSql('ALTER TABLE users DROP COLUMN password_hash');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN password_hash text NULL');
        $this->addSql("ALTER TABLE users ADD COLUMN global_role text NULL CHECK (global_role IN ('operator'))");
        $this->addSql("UPDATE users SET global_role = 'operator' WHERE id IN (
            SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.role_key IN ('ADMIN', 'OPERATOR'))");
        $this->addSql('DROP TABLE auth_login_tokens');
        $this->addSql('DROP TABLE user_roles');
        $this->addSql('DROP TABLE role_permissions');
        $this->addSql('DROP TABLE roles');
    }
}
