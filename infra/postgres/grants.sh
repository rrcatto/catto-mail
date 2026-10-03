#!/bin/sh
# Smarthost table grants (docs/schema/schema.md §6, D-15).
#
# Runs with the administrative connection (POSTGRES_USER) after the Doctrine
# migrations (smarthost-db-migrate) and applies infra/postgres/grants.sql. It is
# idempotent and exact; re-run it after every migration run.
set -eu
file_env() {
    var="$1"; eval "fv=\${${var}_FILE:-}"
    if [ -n "$fv" ]; then eval "export $var=\"\$(cat \"\$fv\")\""; fi
}
file_env POSTGRES_PASSWORD

export PGHOST="$SMARTHOST_DB_HOST" PGPORT="$SMARTHOST_DB_PORT" PGUSER="$POSTGRES_USER" \
       PGPASSWORD="$POSTGRES_PASSWORD" PGDATABASE="$SMARTHOST_DB_NAME" PGSSLMODE="$SMARTHOST_DB_SSLMODE"

for _ in $(seq 1 60); do pg_isready -q && break; sleep 1; done
pg_isready -q || { echo "grants: PostgreSQL not ready" >&2; exit 1; }

psql -q -X \
  -v app="$APP_DB_USER" -v webhook="$APP_WEBHOOK_DB_USER" \
  -v validator="$VALIDATOR_DB_USER" -v delivery="$DELIVERY_DB_USER" \
  -f "$(dirname "$0")/grants.sql"

psql -At -X -c "SELECT 'grants: '||count(*)||' table privileges held by runtime roles'
                FROM information_schema.role_table_grants
                WHERE grantee IN ('$APP_DB_USER','$APP_WEBHOOK_DB_USER','$VALIDATOR_DB_USER','$DELIVERY_DB_USER')"
echo "grants: complete"
