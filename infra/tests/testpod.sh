#!/usr/bin/env bash
# Shared throwaway test pod for the Phase 2 and Phase 3 harnesses (sourced).
#
# testpod_up starts a pod WITHOUT any network (--network none: members share only
# loopback; nothing can reach the Internet or the development pod), labelled
# project=smarthost, with PostgreSQL 16.15, runs the real role bootstrap, the
# Doctrine migrations on the empty database (as smarthost_owner) and the
# infrastructure grants. Credentials are random per run. testpod_down removes it.
# shellcheck disable=SC2034  # variables are used by the sourcing harness
set -euo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PG_IMAGE=docker.io/library/postgres:16.15-trixie
APP_TEST_IMAGE=localhost/smarthost-app-test:dev
POD="smarthost-test-$$"
DB=smarthost

rnd() { head -c 24 /dev/urandom | base64 | tr -d '/+=' | head -c 32; }
ADMIN_PW="$(rnd)"; OWNER_PW="$(rnd)"; APP_PW="$(rnd)"; WEBHOOK_PW="$(rnd)"; VALIDATOR_PW="$(rnd)"; DELIVERY_PW="$(rnd)"
ENC_KEY="test1:$(head -c 32 /dev/urandom | base64)"
APP_SECRET_VALUE="$(rnd)"

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
app_env=(
  "${common_env[@]}" -e SMARTHOST_DB_NAME="$DB"
  -e SMARTHOST_DB_OWNER_PASSWORD="$OWNER_PW" -e APP_DB_PASSWORD="$APP_PW"
  -e APP_ENV=test -e APP_SECRET="$APP_SECRET_VALUE" -e SMARTHOST_ENV=test -e SMARTHOST_PUBLIC_BASE_URL=https://smarthost.localhost
  -e SMARTHOST_LOG_LEVEL=info -e SMARTHOST_LOG_FORMAT=json -e SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS=false
  -e TRUSTED_PROXIES=127.0.0.1 -e APP_API_RATE_LIMIT_PER_MINUTE=100000 -e APP_API_MAX_REQUEST_BYTES=10485760
  -e APP_SEND_JOB_MAX_RECIPIENTS=10000 -e APP_SEND_JOB_MAX_RECIPIENTS_PER_BATCH=500
  -e APP_ENCRYPTION_KEYS="$ENC_KEY" -e APP_WEBHOOK_SECRET_OVERLAP_HOURS=24 -e APP_DOMAIN_VERIFICATION_RECHECK_HOURS=24
)

testpod_down() { podman pod rm -f "$POD" >/dev/null 2>&1 || true; }

pg_admin() { podman run --rm -i --pod "$POD" --label project=smarthost -e PGPASSWORD="$ADMIN_PW" "$PG_IMAGE" \
  psql -X -q -v ON_ERROR_STOP=1 -h 127.0.0.1 -U postgres "$@"; }
infra() { podman run --rm --pod "$POD" --label project=smarthost "${bootstrap_env[@]}" -e SMARTHOST_DB_NAME="$1" \
  -v "$REPO/infra/postgres:/infra:ro" --entrypoint "/infra/$2" "$PG_IMAGE"; }
app() { podman run --rm --pod "$POD" --label project=smarthost "${app_env[@]}" "$APP_TEST_IMAGE" "$@"; }

# testpod_up [extra databases...]: each extra database gets the role bootstrap too.
testpod_up() {
  echo "test pod: building $APP_TEST_IMAGE"
  podman build -q --target test -t "$APP_TEST_IMAGE" -f "$REPO/app/Containerfile" "$REPO" >/dev/null
  podman pod create --name "$POD" --network none --label project=smarthost >/dev/null
  podman run -d --pod "$POD" --name "$POD-postgres" --label project=smarthost \
    -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD="$ADMIN_PW" -e POSTGRES_DB="$DB" "$PG_IMAGE" >/dev/null
  for _ in $(seq 1 60); do
    podman exec "$POD-postgres" pg_isready -q -h 127.0.0.1 -U postgres -d "$DB" 2>/dev/null && break; sleep 1
  done
  # The image's init restarts the server once; wait for the final instance.
  sleep 2; for _ in $(seq 1 30); do podman exec "$POD-postgres" pg_isready -q -h 127.0.0.1 -U postgres -d "$DB" && break; sleep 1; done
  echo "test pod: role bootstrap"
  infra "$DB" bootstrap.sh | sed 's/^/  /'
  for extra in "$@"; do
    pg_admin -d "$DB" -c "CREATE DATABASE $extra"
    infra "$extra" bootstrap.sh >/dev/null
  done
  echo "test pod: Doctrine migrations (empty database, as smarthost_owner)"
  app php bin/console doctrine:migrations:migrate --no-interaction 2>&1 | grep -v '^\s*$' | sed 's/^/  /'
  echo "test pod: grants"
  infra "$DB" grants.sh | sed 's/^/  /'
}
