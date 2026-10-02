#!/bin/sh
# Smarthost PostgreSQL role bootstrap (spec postgres.roles_and_privileges, D-15).
#
# Roles are infrastructure, not Doctrine schema. This idempotent script runs
# with the administrative connection (POSTGRES_USER) and:
#   * creates/updates the five least-privilege login identities;
#   * makes smarthost_owner the owner of the database and the public schema
#     (Doctrine migrations run as the owner from Phase 2 onwards);
#   * gives runtime roles CONNECT + schema USAGE only - never CREATE.
# Table-level grants (docs/schema/schema.md §6) are applied by the same tooling
# once Phase 2 migrations have created the tables.
set -eu
file_env() {
    var="$1"; eval "fv=\${${var}_FILE:-}"
    if [ -n "$fv" ]; then eval "export $var=\"\$(cat \"\$fv\")\""; fi
}
for v in POSTGRES_PASSWORD SMARTHOST_DB_OWNER_PASSWORD APP_DB_PASSWORD APP_WEBHOOK_DB_PASSWORD \
         VALIDATOR_DB_PASSWORD DELIVERY_DB_PASSWORD; do file_env "$v"; done

export PGHOST="$SMARTHOST_DB_HOST" PGPORT="$SMARTHOST_DB_PORT" PGUSER="$POSTGRES_USER" \
       PGPASSWORD="$POSTGRES_PASSWORD" PGDATABASE="$SMARTHOST_DB_NAME" PGSSLMODE="$SMARTHOST_DB_SSLMODE"

for i in $(seq 1 60); do pg_isready -q && break; sleep 1; done
pg_isready -q || { echo "bootstrap: PostgreSQL not ready" >&2; exit 1; }

psql -v ON_ERROR_STOP=1 -q \
  -v db="$SMARTHOST_DB_NAME" \
  -v owner="$SMARTHOST_DB_OWNER_USER" -v owner_pw="$SMARTHOST_DB_OWNER_PASSWORD" \
  -v app="$APP_DB_USER"               -v app_pw="$APP_DB_PASSWORD" \
  -v webhook="$APP_WEBHOOK_DB_USER"   -v webhook_pw="$APP_WEBHOOK_DB_PASSWORD" \
  -v validator="$VALIDATOR_DB_USER"   -v validator_pw="$VALIDATOR_DB_PASSWORD" \
  -v delivery="$DELIVERY_DB_USER"     -v delivery_pw="$DELIVERY_DB_PASSWORD" <<'SQL'
-- Create missing roles (idempotent), then (re)apply attributes and passwords.
SELECT format('CREATE ROLE %I LOGIN', r) FROM unnest(ARRAY[:'owner', :'app', :'webhook', :'validator', :'delivery']) AS r
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) \gexec

ALTER ROLE :"owner"     LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'owner_pw';
ALTER ROLE :"app"       LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'app_pw';
ALTER ROLE :"webhook"   LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'webhook_pw';
ALTER ROLE :"validator" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'validator_pw';
ALTER ROLE :"delivery"  LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'delivery_pw';

ALTER DATABASE :"db" OWNER TO :"owner";
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"db" TO :"app", :"webhook", :"validator", :"delivery";

ALTER SCHEMA public OWNER TO :"owner";
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO :"app", :"webhook", :"validator", :"delivery";
SQL

psql -At -c "SELECT 'bootstrap: role '||rolname||' login='||rolcanlogin||' superuser='||rolsuper||' createdb='||rolcreatedb||' createrole='||rolcreaterole
             FROM pg_roles WHERE rolname LIKE 'smarthost\_%' ORDER BY rolname"
echo "bootstrap: complete"
