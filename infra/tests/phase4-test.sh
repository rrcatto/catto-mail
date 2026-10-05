#!/usr/bin/env bash
# Smarthost Phase 4/5 test harness, part 1: Go delivery daemon in isolation
# (smarthostctl test phase4 and test phase5 run this same suite).
#
#   1. static checks: gofmt, go vet (also with the integration tag);
#   2. Go unit tests (no network, no database): D-32 vectors, identifiers/VERP,
#      MIME, tracking, SMTP submission outcomes, Postfix log parsing and
#      generations, snapshots, pacing, projection, configuration;
#   3. integration tests in the throwaway, network-less test pod of
#      infra/tests/testpod.sh (PostgreSQL 16.15, real role bootstrap, Doctrine
#      migrations, grants) as the least-privilege smarthost_delivery role, with
#      an in-process submission server and fixture Postfix logs/snapshots.
#
# Part 2 (infra/tests/phase4-e2e.sh) runs against the development pod's real
# Postfix, OpenDKIM and Mailpit. Usage: infra/tests/phase4-test.sh [go test args...]
set -euo pipefail
# shellcheck source=infra/tests/testpod.sh
source "$(dirname "${BASH_SOURCE[0]}")/testpod.sh"
DEL_TEST_IMAGE=localhost/smarthost-delivery-test:dev
trap testpod_down EXIT

echo "phase4-test: building $DEL_TEST_IMAGE (gofmt and go vet run during the build)"
podman build -q --target test -t "$DEL_TEST_IMAGE" -f "$REPO/delivery/Containerfile" "$REPO" >/dev/null

echo "phase4-test: Go unit tests (no network)"
podman run --rm --network none --label project=smarthost "$DEL_TEST_IMAGE" -count=1 "${@:-./...}" 2>&1 | sed 's/^/  /'

testpod_up
echo "phase4-test: integration tests (PostgreSQL, smarthost_delivery role)"
podman run --rm --pod "$POD" --label project=smarthost "${common_env[@]}" \
  -e SMARTHOST_DB_NAME="$DB" -e SMARTHOST_DB_OWNER_PASSWORD="$OWNER_PW" -e DELIVERY_DB_PASSWORD="$DELIVERY_PW" \
  -e PHASE4_DEBUG="${PHASE4_DEBUG:-}" "$DEL_TEST_IMAGE" -count=1 -tags integration -timeout 20m -v -run "${PHASE4_RUN:-.}" ./internal/integration 2>&1 \
  | grep -v -E '^\s*$' | sed 's/^/  /'
echo "phase4-test: PASS"
