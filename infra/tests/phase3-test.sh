#!/usr/bin/env bash
# Smarthost Phase 3 test harness: Python validation worker.
#
# First the validator's static checks (Ruff lint, mypy types), then, in the
# throwaway, network-less test pod of infra/tests/testpod.sh (PostgreSQL 16.15,
# real role bootstrap, Doctrine migrations, grants):
#   1. pytest: unit tests, plus database tests (leases, fencing, suspension,
#      metering, completion) and worker tests with fake DNS / fake SMTP, all as
#      the least-privilege validator role;
#   2. end to end: a fake DNS server (validator test image, UDP/TCP :53 on the pod
#      loopback) and the fake SMTP service (:2525) join the pod; Symfony creates
#      jobs through /v1 (incl. a 10,000-address job and a suspended client's job);
#      the real validator process runs, is killed with SIGKILL mid-job, restarts
#      and finishes after its predecessor's leases expire; Symfony then verifies
#      the results through /v1 and the database. The fake SMTP command log must
#      contain no DATA.
# Nothing reaches the Internet. Usage: infra/tests/phase3-test.sh [pytest args...]
set -euo pipefail
# shellcheck source=infra/tests/testpod.sh
source "$(dirname "${BASH_SOURCE[0]}")/testpod.sh"
VAL_TEST_IMAGE=localhost/smarthost-validator-test:dev
VAL_IMAGE=localhost/smarthost-validator:dev
SMTP_IMAGE=localhost/smarthost-fake-smtp:dev
EXCHANGE="$POD-exchange"
cleanup() { testpod_down; podman volume rm -f "$EXCHANGE" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "phase3-test: building validator images"
podman build -q --target test -t "$VAL_TEST_IMAGE" -f "$REPO/validator/Containerfile" "$REPO" >/dev/null
podman build -q --target runtime -t "$VAL_IMAGE" -f "$REPO/validator/Containerfile" "$REPO" >/dev/null
podman build -q -t "$SMTP_IMAGE" "$REPO/tests/fake-smtp" >/dev/null
echo "phase3-test: static checks (ruff, mypy; validator/pyproject.toml)"
podman run --rm --network none --label project=smarthost --entrypoint sh "$VAL_TEST_IMAGE" -c \
  'ruff check --no-cache . && mypy' | sed 's/^/  /'
testpod_up

db_env=("${common_env[@]}" -e SMARTHOST_DB_NAME="$DB" -e VALIDATOR_DB_PASSWORD="$VALIDATOR_PW" -e SMARTHOST_DB_OWNER_PASSWORD="$OWNER_PW")
echo "phase3-test: pytest (unit + database + worker)"
podman run --rm --pod "$POD" --label project=smarthost "${db_env[@]}" "$VAL_TEST_IMAGE" -p no:cacheprovider -q "$@"

echo "phase3-test: end to end"
podman volume create --label project=smarthost "$EXCHANGE" >/dev/null
podman run --rm -v "$EXCHANGE:/exchange" --label project=smarthost --entrypoint chmod "$PG_IMAGE" 0777 /exchange
podman run -d --pod "$POD" --name "$POD-fake-dns" --label project=smarthost --entrypoint python "$VAL_TEST_IMAGE" \
  -m tests.fakes.fake_dns --zone tests/e2e/zone.json --host 127.0.0.1 --port 53 >/dev/null
podman run -d --pod "$POD" --name "$POD-fake-smtp" --label project=smarthost -e FAKE_SMTP_PORT=2525 "$SMTP_IMAGE" >/dev/null
for _ in $(seq 1 30); do podman exec "$POD-fake-smtp" python /opt/fake-smtp/fake_smtp.py --check 2>/dev/null && break; sleep 1; done

app_x() { podman run --rm --pod "$POD" --label project=smarthost "${app_env[@]}" -v "$EXCHANGE:/exchange" "$APP_TEST_IMAGE" "$@"; }
app_x php tests/Phase3/e2e.php prepare /exchange/plan.json | sed 's/^/  /'
big_job="$(podman run --rm -v "$EXCHANGE:/exchange" --entrypoint cat "$PG_IMAGE" /exchange/plan.json | python3 -c 'import json,sys; print(json.load(sys.stdin)["big_job"])')"

worker_env=(
  "${common_env[@]}" -e SMARTHOST_DB_NAME="$DB" -e VALIDATOR_DB_PASSWORD="$VALIDATOR_PW"
  -e SMARTHOST_ENV=test -e SMARTHOST_LOG_LEVEL=info -e SMARTHOST_LOG_FORMAT=json
  -e VALIDATOR_WORKER_ID=e2e -e VALIDATOR_CHUNK_SIZE=250 -e VALIDATOR_LEASE_SECONDS=15 -e VALIDATOR_POLL_INTERVAL_SECONDS=2
  -e VALIDATOR_GLOBAL_CONCURRENCY=20 -e VALIDATOR_PER_DOMAIN_CONCURRENCY=2 -e VALIDATOR_PER_MX_CONCURRENCY=2
  -e VALIDATOR_MAX_ATTEMPTS=3 -e VALIDATOR_RETRY_BASE_SECONDS=1 -e VALIDATOR_RETRY_MAX_SECONDS=2
  -e VALIDATOR_DNS_RESOLVERS=127.0.0.1 -e VALIDATOR_DNS_TIMEOUT_SECONDS=2
  -e VALIDATOR_SMTP_PROBE_ENABLED=true -e VALIDATOR_SMTP_ROUTE_OVERRIDE=127.0.0.1:2525
  -e VALIDATOR_SMTP_HELO_HOSTNAME=validator.smarthost.test -e VALIDATOR_SMTP_MAIL_FROM=validator@bounce.smarthost.test
  -e VALIDATOR_SMTP_CONNECT_TIMEOUT_SECONDS=2 -e VALIDATOR_SMTP_COMMAND_TIMEOUT_SECONDS=2
)
processed() { pg_admin -d "$DB" -At -c "SELECT processed_count FROM validation_jobs WHERE id = '$big_job'"; }

echo "  worker run 1 (killed with SIGKILL after >= 2500 of 10,000 addresses)"
podman run -d --pod "$POD" --name "$POD-validator-1" --label project=smarthost "${worker_env[@]}" "$VAL_IMAGE" run >/dev/null
for _ in $(seq 1 600); do [[ "$(processed)" -ge 2500 ]] && break; sleep 0.5; done
podman kill --signal KILL "$POD-validator-1" >/dev/null
echo "    processed before the crash: $(processed); leases left behind: $(pg_admin -d "$DB" -At -c "SELECT count(*) FROM validation_addresses WHERE processing_state = 'claimed'")"

echo "  worker run 2 (restart; reclaims expired leases; exits when idle)"
start=$SECONDS
podman run --rm --pod "$POD" --label project=smarthost "${worker_env[@]}" -v "$EXCHANGE:/exchange" "$VAL_IMAGE" \
  run --exit-when-idle --stats-file /exchange/stats.json > /dev/null
echo "    finished in $((SECONDS - start)) s"

echo "  verify through /v1 and the database"
app_x php tests/Phase3/e2e.php verify /exchange/plan.json /exchange/stats.json | tee "$REPO/infra/.generated/phase3-e2e-report.json" | python3 -c '
import json, sys
r = json.load(sys.stdin)
s = r["worker_stats"]
print("    big job:", r["big_job"]["status"], r["big_job"]["processed_count"], json.dumps(r["big_job"]["classification_counts"]))
print("    usage/outbox:", {k: (v["usage_quantity"], v["usage_rows"], v["outbox"], v["max_attempt_count"]) for k, v in r["db"].items()})
print("    run 2 stats: claimed=%d finalised=%d retried=%d lost=%d max_claim=%d max_in_flight=%d max_global=%d/%d max_domain=%d/%d max_mx=%d/%d dns_lookups=%d peak_rss=%d KiB" % (
    s["claimed"], s["finalised"], s["retried"], s["lost"], s["max_claim_size"], s["max_in_flight"], s["max_global"], s["limit_global"],
    s["max_per_domain"], s["limit_per_domain"], s["max_per_mx"], s["limit_per_mx"], s["dns_domain_lookups"], s["peak_rss_kib"]))
print("    failures:", r["failures"])
sys.exit(1 if r["failures"] else 0)'

cmds="$(podman logs "$POD-fake-smtp" 2>&1 | python3 -c '
import collections, json, sys
c = collections.Counter(json.loads(l).get("cmd") for l in sys.stdin if l.startswith("{"))
c.pop(None, None); print(json.dumps(dict(sorted(c.items()))))')"
echo "  fake SMTP received: $cmds"
[[ "$cmds" != *'"DATA"'* && "$cmds" != *'"BDAT"'* ]] || { echo "phase3-test: FAIL - the fake SMTP server received DATA" >&2; exit 1; }
echo "phase3-test: PASS"
