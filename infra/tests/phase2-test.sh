#!/usr/bin/env bash
# Smarthost Phase 2 test harness: Symfony application (PHPUnit).
#
# Uses the throwaway, network-less test pod of infra/tests/testpod.sh (PostgreSQL
# 16.15, real role bootstrap, Doctrine migrations on an empty database, grants),
# loads the reference schema into <db>_reference for the structural comparison
# (and creates <db>_migrations for the rollback tests), then runs PHPUnit as the
# least-privilege application role. Usage: infra/tests/phase2-test.sh [phpunit args...]
set -euo pipefail
# shellcheck source=infra/tests/testpod.sh
source "$(dirname "${BASH_SOURCE[0]}")/testpod.sh"
trap testpod_down EXIT

testpod_up "${DB}_migrations" "${DB}_reference"
echo "phase2-test: reference schema -> ${DB}_reference (comparison only)"
pg_admin -d "${DB}_reference" <"$REPO/docs/schema/reference-schema.sql"

echo "phase2-test: PHPUnit"
app php -d memory_limit=1G vendor/bin/phpunit "$@"
