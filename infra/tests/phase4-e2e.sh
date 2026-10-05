#!/usr/bin/env bash
# Smarthost Phase 4 test harness, part 2: end to end through the real
# development transport of the running `smarthost` pod:
#
#   Symfony /v1 -> PostgreSQL -> Go delivery -> Postfix :587 (SASL, STARTTLS)
#   -> OpenDKIM (milter) -> Postfix capture relay -> Mailpit
#
# Scenarios (all mail stays on the internal network; Mailpit captures it):
#   A  subscription (tracking, Reply-To) + transactional jobs via the dev daemon:
#      headers, VERP envelope, tracking, DKIM, events, purge, usage, completion
#   B  OpenDKIM stopped: tempfail and retry; content kept, nothing metered,
#      nothing reaches Mailpit unsigned; signed delivery once it returns
#   C  Mailpit stopped: Postfix deferral (connection_failure), then delivery
#   D  crash: a worker is SIGKILLed mid-job; after its lease expires another
#      worker finishes; no message is submitted twice, nothing is lost
#   E  10,000 recipients over 50 domains with a log rotation during ingestion:
#      bounded memory, pacing limits, exactly-once submission and metering
#
# D and E use throwaway worker containers in the pod (short lease, test pacing)
# while the pod's own delivery container is stopped; it is started again at
# the end. Usage: infra/tests/phase4-e2e.sh [A B C D E]
set -uo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CTL="$REPO/infra/bin/smarthostctl"
GEN="$REPO/infra/.generated"
TOOLS=localhost/smarthost-testtools:dev
SCEN="${*:-A B C D E}"
PASS=0; FAIL=0
ev() { grep -E "^$1=" "$REPO/infra/.env" | head -n1 | cut -d= -f2-; }
pass() { PASS=$((PASS+1)); echo "  PASS  $*"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL  $*"; }
jget() { python3 -c "import json,sys; d=json.loads(sys.stdin.read().strip().splitlines()[-1]); print(eval(sys.argv[1], {}, {'d': d}))" "$1"; }
check() { local desc="$1" out="$2"; local f; f="$(jget "d.get('failures', [])" <<<"$out")"
  if [[ "$f" == "[]" ]]; then pass "$desc"; else fail "$desc: ${f:0:900}"; fi; }

podman pod exists smarthost && [[ "$(podman pod inspect smarthost --format '{{.State}}')" == Running ]] \
  || { echo "phase4-e2e: the smarthost pod is not running (smarthostctl start)" >&2; exit 2; }
"$GEN/podman/smarthost-pod.sh" wait-healthy 300 >/dev/null || { echo "phase4-e2e: services not healthy" >&2; exit 2; }
podman build -q -t "$TOOLS" "$REPO/infra/tests" >/dev/null

KEY="$(podman exec -u www-data smarthost-symfony-app php bin/console smarthost:dev:bootstrap 2>/dev/null | sed -n 's/^api_key: //p')"
[[ -n "$KEY" ]] || { echo "phase4-e2e: could not obtain a development API key" >&2; exit 2; }
podman exec smarthost-opendkim sh -c 'cat "$OPENDKIM_KEY_DIR"/smarthost-dev.test/phase1.txt' > "$GEN/phase4-dkim.txt"
drv() {
  podman run --rm --network smarthost-internal --label project=smarthost \
    --user "$(ev SMARTHOST_DELIVERY_UID):$(ev SMARTHOST_SPOOL_GID)" -v smarthost-postfix-observability:/obs:ro \
    -v "$GEN/phase4-dkim.txt:/t/key.txt:ro" -e E2E_API_KEY="$KEY" \
    -e SMARTHOST_DB_NAME="$(ev SMARTHOST_DB_NAME)" -e SMARTHOST_DB_OWNER_USER="$(ev SMARTHOST_DB_OWNER_USER)" \
    -e SMARTHOST_DB_OWNER_PASSWORD="$(ev SMARTHOST_DB_OWNER_PASSWORD)" -e SMARTHOST_BOUNCE_DOMAIN="$(ev SMARTHOST_BOUNCE_DOMAIN)" \
    -e SMARTHOST_PUBLIC_BASE_URL="$(ev SMARTHOST_PUBLIC_BASE_URL)" --entrypoint python "$TOOLS" /opt/tests/phase4_e2e.py "$@"
}
# A throwaway worker in the pod with a short lease and test pacing.
worker() {  # name extra-args...
  local name="$1"; shift
  podman run -d --name "$name" --pod smarthost --label project=smarthost --env-file "$GEN/env/delivery.env" \
    --user "$(ev SMARTHOST_DELIVERY_UID):$(ev SMARTHOST_SPOOL_GID)" \
    -v smarthost-postfix-observability:"$(ev SMARTHOST_POSTFIX_OBSERVABILITY_DIR)":ro -v smarthost-dsn-spool:"$(ev SMARTHOST_DSN_SPOOL_DIR)" \
    -e DELIVERY_WORKER_ID="$name" -e DELIVERY_LEASE_SECONDS=20 -e DELIVERY_POLL_INTERVAL_SECONDS=1 \
    -e DELIVERY_GLOBAL_CONCURRENCY=20 -e DELIVERY_PER_DOMAIN_CONCURRENCY=4 -e DELIVERY_PER_DOMAIN_RATE_PER_MINUTE=6000 \
    "$@" localhost/smarthost-delivery:dev run --stats-file /tmp/stats.json >/dev/null
}
sql() { drv sql "$1" | jget "d['rows'][0][0]"; }

if [[ " $SCEN " == *" A "* ]]; then
  echo "== A subscription + transactional through Postfix, OpenDKIM and Mailpit (dev daemon)"
  out="$(drv basic-create)"; tag="$(jget "d['tag']" <<<"$out")"; j1="$(jget "d['subscription_job']" <<<"$out")"; j2="$(jget "d['transactional_job']" <<<"$out")"
  for j in "$j1" "$j2"; do
    [[ "$(drv wait "$j" completed 180 | jget "d['ok']")" == True ]] && pass "job $j completed (all messages remote_accepted)" || fail "job $j not completed"
  done
  check "Mailpit copies: VERP Return-Path, Message-ID, headers, tracking, DKIM; DB: events, purge, usage, outbox, counts" "$(drv basic-verify "$tag" "$j1" "$j2")"
  check "each message reached Postfix exactly once" "$(drv no-duplicates "$j1")"
fi

if [[ " $SCEN " == *" B "* ]]; then
  echo "== B OpenDKIM unavailable: tempfail, retry, never unsigned"
  podman stop smarthost-opendkim >/dev/null
  tag="p4dkim-$(date +%s)"
  jb="$(drv create "{\"recipients\": [{\"external_recipient_reference\": \"b1\", \"email_address\": \"dkim-down@example.com\", \"subject\": \"$tag\", \"text_body\": \"signed only\"}]}" | jget "d['job']")"
  sleep 20
  st="$(sql "SELECT m.current_status || '/' || coalesce(m.postfix_queue_id, '-') || '/' || (r.content_purged_at IS NULL)::text FROM messages m JOIN send_job_recipients r ON r.id = m.send_job_recipient_id WHERE m.send_job_id = '$jb'")"
  [[ "$st" == "queued/-/true" ]] && pass "while OpenDKIM is down the message stays queued, without queue id, content kept ($st)" || fail "state while OpenDKIM down: $st"
  [[ "$(sql "SELECT count(*) FROM usage_records u JOIN messages m ON m.id = u.reference_id WHERE m.send_job_id = '$jb'")" == 0 ]] && pass "no message_submitted usage before Postfix accepts" || fail "usage recorded while OpenDKIM down"
  [[ "$(drv mailpit-count "$tag" | jget "d['count']")" == 0 ]] && pass "nothing reached Mailpit unsigned" || fail "message reached Mailpit while OpenDKIM was down"
  # Captured first: with pipefail, `podman logs | grep -q` can fail on SIGPIPE.
  dlog="$(podman logs smarthost-delivery 2>&1)"
  [[ "$(grep -c 'temporary submission failure' <<<"$dlog")" -ge 1 ]] && pass "the daemon logged temporary submission failures and retries" || fail "no temporary failure logged"
  podman start smarthost-opendkim >/dev/null; "$GEN/podman/smarthost-pod.sh" wait-healthy 120 >/dev/null
  [[ "$(drv wait "$jb" completed 240 | jget "d['ok']")" == True ]] && pass "after OpenDKIM returned the job completed" || fail "job $jb not completed after OpenDKIM returned"
  check "delivered message is DKIM-signed and fully recorded" "$(drv verify-single "$jb" "$tag")"
fi

if [[ " $SCEN " == *" C "* ]]; then
  echo "== C Postfix deferral (Mailpit down), then delivery"
  podman stop smarthost-mailpit >/dev/null
  tag="p4defer-$(date +%s)"
  jc="$(drv create "{\"recipients\": [{\"external_recipient_reference\": \"c1\", \"email_address\": \"deferred@example.com\", \"subject\": \"$tag\", \"text_body\": \"later\"}]}" | jget "d['job']")"
  [[ "$(drv wait-sql "SELECT current_status FROM messages WHERE send_job_id = '$jc'" deferred 90 | jget "d['ok']")" == True ]] \
    && pass "connection failure to the relay projects deferred" || fail "message never deferred"
  [[ "$(sql "SELECT status FROM send_jobs WHERE id = '$jc'")" == dispatched ]] && pass "job is dispatched (submitted) but not completed while deferred" || fail "job state while deferred"
  podman start smarthost-mailpit >/dev/null; "$GEN/podman/smarthost-pod.sh" wait-healthy 120 >/dev/null
  podman exec smarthost-postfix postqueue -f >/dev/null
  [[ "$(drv wait "$jc" completed 180 | jget "d['ok']")" == True ]] && pass "delivered after the relay returned; job completed" || fail "job $jc not completed"
  evs="$(sql "SELECT string_agg(event_type, ',' ORDER BY occurred_at, recorded_at) FROM message_events e JOIN messages m ON m.id = e.message_id WHERE m.send_job_id = '$jc' AND e.event_source = 'postfix_log'")"
  [[ "$evs" == *connection_failure* && "$evs" == *delivery_attempt* && "$evs" == *remote_accepted* ]] && pass "event history: $evs" || fail "event history: $evs"
fi

if [[ " $SCEN " == *" D "* ]]; then
  echo "== D crash and restart (SIGKILL mid-job, reclaim after lease expiry)"
  podman stop smarthost-delivery >/dev/null
  jd="$(drv create '{"generate": {"n": 600, "domains": ["d1.example","d2.example","d3.example","d4.example","d5.example","d6.example"], "tag": "p4crash"}}' | jget "d['job']")"
  worker p4-worker-a
  drv wait-sql "SELECT (count(*) >= 150)::text FROM messages WHERE send_job_id = '$jd' AND postfix_queue_id IS NOT NULL" true 300 >/dev/null
  podman kill --signal KILL p4-worker-a >/dev/null
  before="$(sql "SELECT count(*) FROM messages WHERE send_job_id = '$jd' AND postfix_queue_id IS NOT NULL")"
  echo "    killed worker A after $before of 600 submissions"
  podman rm -f p4-worker-a >/dev/null
  worker p4-worker-b
  [[ "$(drv wait "$jd" completed 600 | jget "d['ok']")" == True ]] && pass "worker B reclaimed the job after the lease expired and it completed" || fail "job $jd not completed after restart"
  [[ "$(sql "SELECT attempt_count FROM send_jobs WHERE id = '$jd'")" -ge 2 ]] && pass "the job was reclaimed (attempt_count >= 2)" || fail "job was not reclaimed"
  check "no lost and no duplicate submission; usage and purge exactly once" "$(drv no-duplicates "$jd")"
  podman rm -f p4-worker-b >/dev/null
fi

if [[ " $SCEN " == *" E "* ]]; then
  echo "== E 10,000 recipients over 50 domains"
  podman stop smarthost-delivery >/dev/null
  domains="$(python3 -c 'import json; print(json.dumps([f"load{i:02d}.example" for i in range(50)]))')"
  t0=$SECONDS
  je="$(drv create "{\"generate\": {\"n\": 10000, \"domains\": $domains, \"tag\": \"p4load\"}}" | jget "d['job']")"
  echo "    created job $je through /v1 in $((SECONDS - t0)) s"
  t1=$SECONDS
  worker p4-worker-load
  drv wait-sql "SELECT (count(*) >= 4000)::text FROM messages WHERE send_job_id = '$je' AND postfix_queue_id IS NOT NULL" true 900 >/dev/null
  "$CTL" systemctl start smarthost-postfix-logrotate.service >/dev/null && echo "    rotated the Postfix log mid-run"
  [[ "$(drv wait "$je" dispatched 1800 | jget "d['ok']")" == True || "$(sql "SELECT status FROM send_jobs WHERE id = '$je'")" == completed ]] \
    && pass "10,000-recipient job dispatched in $((SECONDS - t1)) s" || fail "job $je not dispatched"
  [[ "$(drv wait "$je" completed 1800 | jget "d['ok']")" == True ]] && pass "job completed in $((SECONDS - t1)) s (every message remote_accepted via the log, across the rotation)" || fail "job $je not completed"
  check "10,000 messages: exactly-once submission, usage and purge; counts" "$(drv no-duplicates "$je")"
  podman stop --time 30 p4-worker-load >/dev/null
  stats="$(podman cp p4-worker-load:/tmp/stats.json - 2>/dev/null | tar -xO 2>/dev/null)"
  echo "    worker stats: $(tr -d '\n ' <<<"$stats")"
  python3 - "$stats" "$(ev SMARTHOST_DELIVERY_UID)" <<'PY' && pass "bounded memory and pacing limits held" || fail "resource bounds"
import json, sys
s = json.loads(sys.argv[1])
assert s["max_inflight_global"] <= 20 and s["max_inflight_domain"] <= 4, s
assert s["peak_rss_kib"] < 256 * 1024, s
PY
  podman rm -f p4-worker-load >/dev/null
fi

podman start smarthost-delivery >/dev/null 2>&1 || true
"$GEN/podman/smarthost-pod.sh" wait-healthy 120 >/dev/null || true
echo "phase4-e2e: $PASS passed, $FAIL failed"
(( FAIL == 0 ))
