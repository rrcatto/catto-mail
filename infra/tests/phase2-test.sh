#!/usr/bin/env bash
# Smarthost Phase 2 test harness.
#
# Builds the application test image and runs, in a throwaway pod WITHOUT any
# network (--network none: members share only loopback; nothing can reach the
# Internet or the development pod):
#   1. PostgreSQL 16.15 (same image as the development pod), empty;
#   2. the real infrastructure role bootstrap (infra/postgres/bootstrap.sh);
#   3. Doctrine migrations as smarthost_owner on the empty database;
#   4. the infrastructure grants (infra/postgres/grants.sh);
#   5. the reference schema loaded into a separate database for comparison;
#   6. PHPUnit as the least-privilege application role.
# Credentials are random per run and never written to disk outside the
# containers. The pod and its containers are removed afterwards (label
# project=smarthost). Usage: infra/tests/phase2-test.sh [phpunit args...]
set -euo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PG_IMAGE=docker.io/library/postgres:16.15-trixie
TEST_IMAGE=localhost/smarthost-app-test:dev
POD="smarthost-p2test-$$"
DB=smarthost

rnd() { head -c 24 /dev/urandom | base64 | tr -d '/+=' | head -c 32; }
ADMIN_PW="$(rnd)"; OWNER_PW="$(rnd)"; APP_PW="$(rnd)"; WEBHOOK_PW="$(rnd)"; VALIDATOR_PW="$(rnd)"; DELIVERY_PW="$(rnd)"
ENC_KEY="test1:$(head -c 32 /dev/urandom | base64)"

cleanup() { podman pod rm -f "$POD" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "phase2-test: building $TEST_IMAGE"
podman build -q --target test -t "$TEST_IMAGE" -f "$REPO/app/Containerfile" "$REPO" >/dev/null

podman pod create --name "$POD" --network none --label project=smarthost >/dev/null
podman run -d --pod "$POD" --name "$POD-postgres" --label project=smarthost \
  -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD="$ADMIN_PW" -e POSTGRES_DB="$DB" "$PG_IMAGE" >/dev/null

common_env=(
  -e SMARTHOST_DB_HOST=127.0.0.1 -e SMARTHOST_DB_PORT=5432 -e SMARTHOST_DB_SSLMODE=disable
  -e SMARTHOST_DB_OWNER_USER=smarthost_owner -e APP_DB_USER=smarthost_app -e APP_WEBHOOK_DB_USER=smarthost_webhook
  -e VALIDATOR_DB_USER=smarthost_validator -e DELIVERY_DB_USER=smarthost_delivery
)
bootstrap_env=(
  "${common_env[@]}" -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD="$ADMIN_PW"
  -e SMARTHOST_DB_OWNER_PASSWORD="$OWNER_PW" -e APP_DB_PASSWORD="$APP_PW" -e APP_WEBHOOK_DB_PASSWORD="$WEBHOOK_PW"
  -e VALIDATOR_DB_PASSWORD="$VALIDATOR_PW" -e DELIVERY_DB_PASSWORD="$DELIVERY_PW"
)
pg_admin() { podman run --rm -i --pod "$POD" --label project=smarthost -e PGPASSWORD="$ADMIN_PW" "$PG_IMAGE" \
  psql -X -q -v ON_ERROR_STOP=1 -h 127.0.0.1 -U postgres "$@"; }
infra() { podman run --rm --pod "$POD" --label project=smarthost "${bootstrap_env[@]}" -e SMARTHOST_DB_NAME="$1" \
  -v "$REPO/infra/postgres:/infra:ro" --entrypoint "/infra/$2" "$PG_IMAGE"; }

for _ in $(seq 1 60); do
  podman exec "$POD-postgres" pg_isready -q -h 127.0.0.1 -U postgres -d "$DB" 2>/dev/null && break; sleep 1
done
# The image's init restarts the server once; wait for the final instance.
sleep 2; for _ in $(seq 1 30); do podman exec "$POD-postgres" pg_isready -q -h 127.0.0.1 -U postgres -d "$DB" && break; sleep 1; done

echo "phase2-test: role bootstrap"
infra "$DB" bootstrap.sh | sed 's/^/  /'
pg_admin -d "$DB" -c "CREATE DATABASE ${DB}_migrations" -c "CREATE DATABASE ${DB}_reference"
infra "${DB}_migrations" bootstrap.sh >/dev/null
echo "phase2-test: reference schema -> ${DB}_reference (comparison only)"
pg_admin -d "${DB}_reference" <"$REPO/docs/schema/reference-schema.sql"

app_env=(
  "${common_env[@]}" -e SMARTHOST_DB_NAME="$DB"
  -e SMARTHOST_DB_OWNER_PASSWORD="$OWNER_PW" -e APP_DB_PASSWORD="$APP_PW"
  -e APP_ENV=test -e APP_SECRET="$(rnd)" -e SMARTHOST_ENV=test -e SMARTHOST_PUBLIC_BASE_URL=https://smarthost.localhost
  -e SMARTHOST_LOG_LEVEL=info -e SMARTHOST_LOG_FORMAT=json -e SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS=false
  -e TRUSTED_PROXIES=127.0.0.1 -e APP_API_RATE_LIMIT_PER_MINUTE=100000 -e APP_API_MAX_REQUEST_BYTES=10485760
  -e APP_SEND_JOB_MAX_RECIPIENTS=10000 -e APP_SEND_JOB_MAX_RECIPIENTS_PER_BATCH=500
  -e APP_ENCRYPTION_KEYS="$ENC_KEY" -e APP_WEBHOOK_SECRET_OVERLAP_HOURS=24 -e APP_DOMAIN_VERIFICATION_RECHECK_HOURS=24
)
app() { podman run --rm --pod "$POD" --label project=smarthost "${app_env[@]}" "$TEST_IMAGE" "$@"; }

echo "phase2-test: Doctrine migrations (empty database, as smarthost_owner)"
app php bin/console doctrine:migrations:migrate --no-interaction 2>&1 | sed 's/^/  /'
echo "phase2-test: grants"
infra "$DB" grants.sh | sed 's/^/  /'

echo "phase2-test: PHPUnit"
app php -d memory_limit=1G vendor/bin/phpunit "$@"
