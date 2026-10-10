#!/usr/bin/env bash
# Smarthost Phase 7 end to end: an external client application against the running
# pod, through the public API and signed webhooks only (infra/tests/phase7_e2e.py,
# no Smarthost database access), with a deterministic external webhook receiver
# (tests/webhook-receiver, its own container on the internal network, alias
# webhook-receiver - the only host on the development SSRF allowlist).
#
#   V  validation job -> Python -> validation.completed -> worker -> receiver -> paged results
#   S  rendered subscription send (501 recipients: two batches, one replayed) -> Go ->
#      Postfix -> OpenDKIM -> Mailpit -> send.completed -> receiver; messages mapped by reference
#   T  tracking: pixel and click from the delivered mail -> events visible through the API
#   B  hard-bounce DSN and C ARF complaint (Phase 5 path) -> signed message.* webhooks
#   O  explicit global opt-out (D-30) -> no webhook of its own
#   W  POST /v1/webhooks/test requires webhook_endpoint_id; without it nothing is recorded or sent
#   F  receiver offline -> retry -> delivered once; worker SIGKILLed mid-request ->
#      re-sent after the lease -> one effect; permanent 400 -> failed -> polling fallback
#   X  SSRF: endpoints at 127.0.0.1 and 169.254.169.254 are refused, nothing is sent
#
# The harness itself (not the client) uses the console and, for DSN injection and
# delivery-state checks, the Phase 5 tool. Usage: infra/tests/phase7-e2e.sh
set -uo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GEN="$REPO/infra/.generated"
TOOLS=localhost/smarthost-testtools:dev
RECV_IMAGE=localhost/smarthost-test-webhook-receiver:dev
RECV=smarthost-test-webhook-receiver
PASS=0; FAIL=0
ev() { python3 "$REPO/infra/lib/smarthost_render.py" get "$1" --env "$REPO/infra/.env"; }  # .env value, else built-in
pass() { PASS=$((PASS+1)); echo "  PASS  $*"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL  $*"; }
jget() { python3 -c "import json,sys; d=json.loads(sys.stdin.read().strip().splitlines()[-1]); print(eval(sys.argv[1], {}, {'d': d}))" "$1"; }

podman pod exists smarthost && [[ "$(podman pod inspect smarthost --format '{{.State}}')" == Running ]] \
  || { echo "phase7-e2e: the smarthost pod is not running (smarthostctl start)" >&2; exit 2; }
"$GEN/podman/smarthost-pod.sh" wait-healthy 300 >/dev/null || { echo "phase7-e2e: services not healthy" >&2; exit 2; }
podman build -q -t "$TOOLS" "$REPO/infra/tests" >/dev/null
podman build -q -t "$RECV_IMAGE" "$REPO/tests/webhook-receiver" >/dev/null

echo "== receiver fixture unit tests (signature, rotation, duplicates, order)"
if podman run --rm --label project=smarthost -e RECEIVER_STATE_FILE=/tmp/s.json --entrypoint python "$RECV_IMAGE" -m unittest test_receiver >/dev/null 2>&1; then
  pass "receiver unit tests"; else fail "receiver unit tests"; fi
if grep -qE "psycopg|SMARTHOST_DB|postgres" "$REPO/infra/tests/phase7_e2e.py"; then fail "client driver references the Smarthost database"
else pass "the client driver has no database access (public API and webhooks only)"; fi

STATE_DIR="$GEN/test-output/webhook-receiver"; mkdir -p "$STATE_DIR"; rm -f "$STATE_DIR/state.json"
podman rm -f "$RECV" >/dev/null 2>&1 || true
# On exit: remove the receiver and disable every endpoint this run registered (harness cleanup).
cleanup() {
  podman rm -f "$RECV" >/dev/null 2>&1 || true
  [[ -n "${CLIENT:-}" ]] && sql "UPDATE webhook_endpoints SET status = 'disabled', updated_at = now() WHERE client_id = '$CLIENT' RETURNING 'ok'" >/dev/null 2>&1
  true
}
trap cleanup EXIT
podman run -d --name "$RECV" --label project=smarthost --network smarthost-internal --network-alias webhook-receiver \
  --user 0 -v "$STATE_DIR:/data:Z" "$RECV_IMAGE" >/dev/null

console() { podman exec -i -u www-data smarthost-symfony-app php bin/console "$@"; }
field() { sed -n "s/^ *$1: //p" | head -n1; }
p5() {
  podman run --rm --network smarthost-internal --label project=smarthost -v "$REPO/delivery/internal/dsn/testdata:/fixtures:ro" \
    -e SMARTHOST_DB_NAME="$(ev SMARTHOST_DB_NAME)" -e SMARTHOST_DB_OWNER_USER="$(ev SMARTHOST_DB_OWNER_USER)" \
    -e SMARTHOST_DB_OWNER_PASSWORD="$(ev SMARTHOST_DB_OWNER_PASSWORD)" -e SMARTHOST_BOUNCE_DOMAIN="$(ev SMARTHOST_BOUNCE_DOMAIN)" \
    --entrypoint python "$TOOLS" /opt/tests/phase5_e2e.py "$@"
}
sql() { p5 sql "$1" | jget "d['rows'][0][0] if d['rows'] else ''"; }
waitsql() { local _; for _ in $(seq 1 "${3:-60}"); do [[ "$(sql "$1")" == "$2" ]] && return 0; sleep 1; done; return 1; }

# Harness setup: a fresh client (so no stale endpoint receives anything), its domain,
# an API key, the opt-out capability, and one webhook endpoint registered through the
# audited console (the dashboard does the same). The client receives only the key and
# the signing secret.
T="$(date +%s)"
NAME="client-$T"   # this run's receiver path: endpoints left by earlier runs never mix in
# Earlier runs (or other suites) may still have send work in the pod; wait so this run's timing is its own.
waitsql "SELECT count(*) FROM send_jobs WHERE status IN ('queued', 'processing')" 0 1800 \
  || { echo "phase7-e2e: send work of an earlier run is still in progress" >&2; exit 2; }
OPERATOR="p7-op-$T@smarthost-dev.test"
console smarthost:user:create "$OPERATOR" --role OPERATOR >/dev/null
CLIENT="$(console smarthost:client:create --company "Phase7 client $T" --contact-email ops@client.example --status active | field client_id)"
console smarthost:domain:add "$CLIENT" smarthost-dev.test >/dev/null
sql "UPDATE sending_domains SET status = 'verified', verified_at = now(), dkim_selector = 'phase1', dkim_status = 'active' WHERE client_id = '$CLIENT' RETURNING 'ok'" >/dev/null
console smarthost:client:global-suppressions "$CLIENT" enable --operator "$OPERATOR" --note "phase7 e2e trusted client" >/dev/null
KEY="$(console smarthost:api-key:create "$CLIENT" --name phase7 | field api_key)"
hook="$(console smarthost:webhook:add "$CLIENT" "http://webhook-receiver:8080/hooks/$NAME" --event validation.completed --event validation.failed \
  --event send.completed --event send.failed --event message.hard_bounced --event message.complained)"
ENDPOINT="$(field webhook_endpoint_id <<<"$hook")"; SECRET="$(field signing_secret <<<"$hook")"
[[ -n "$KEY" && -n "$SECRET" ]] || { echo "phase7-e2e: setup failed" >&2; exit 2; }
drv() {
  podman run --rm --network smarthost-internal --label project=smarthost -e CLIENT_API_KEY="$KEY" -e CLIENT_WEBHOOK_ENDPOINT_ID="$ENDPOINT" \
    -e RECEIVER_URL=http://webhook-receiver:8080 -e RECEIVER_ENDPOINT="$NAME" --entrypoint python "$TOOLS" /opt/tests/phase7_e2e.py "$@"
}
for _ in $(seq 1 20); do drv control secrets "{\"endpoint\": \"$NAME\", \"secrets\": [\"$SECRET\"]}" >/dev/null 2>&1 && break; sleep 1; done

echo "== V validation through the public API and a signed webhook"
v="$(drv validate "$T")"
[[ "$(jget "d['webhook']" <<<"$v")" == True ]] && pass "validation.completed received and verified by the client" || fail "no validation webhook: $v"
[[ "$(jget "d['status']" <<<"$v")" == completed && "$(jget "d['results']" <<<"$v")" == 3 ]] && pass "results paged (limit 2) and complete" || fail "results: $v"
[[ "$(jget "d['refs_preserved']" <<<"$v")" == True ]] && pass "results mapped back by external_address_reference" || fail "refs: $v"
[[ "$(jget "d['mapping']['subscriber-18']['suggested']" <<<"$v")" == *gmail.com ]] && pass "typo suggestion reported, not applied" || fail "suggestion: $v"

echo "== S rendered batched send through Go, Postfix, OpenDKIM and Mailpit"
s="$(drv send "$T" 501)"
M0="$(jget "d['first_message']" <<<"$s")"; M1="$(jget "d['second_message']" <<<"$s")"
[[ "$(jget "d['batches']" <<<"$s")" == 2 && "$(jget "d['total_recipients']" <<<"$s")" == 501 ]] && pass "two batches (500 + 1); the replayed batch added nothing" || fail "batches: $s"
[[ "$(jget "d['replay_status']" <<<"$s")" == 20* ]] && pass "same Idempotency-Key replay answered without a duplicate" || fail "replay: $s"
[[ "$(jget "d['webhook']" <<<"$s")" == True && "$(jget "d['status']" <<<"$s")" == completed ]] && pass "send.completed received; job completed" || fail "send webhook: $s"
[[ "$(jget "d['refs_complete']" <<<"$s")" == True ]] && pass "501 messages mapped by external_recipient_reference" || fail "messages: $s"
[[ "$(jget "d['summary']['remote_accepted']" <<<"$s")" == 501 ]] && pass "all 501 remote_accepted (captured by Mailpit)" || fail "summary: $s"

echo "== T tracking from the delivered mail"
t="$(drv track "$T" "$M0")"
[[ "$(jget "d['open_recorded'] and d['click_recorded']" <<<"$t")" == True ]] && pass "recorded open and click visible through /v1/messages/{id}/events" || fail "tracking: $t"
[[ "$(jget "d['unsubscribe_tracked']" <<<"$t")" == False ]] && pass "the client's unsubscribe link is not click-tracked" || fail "unsubscribe tracked: $t"

echo "== B/C hard bounce and complaint reach the client as signed webhooks"
p5 inject postfix-hard-5.1.1.eml "$M0" >/dev/null
[[ "$(drv await message.hard_bounced "$M0" 120 | jget "d['ok']")" == True ]] && pass "DSN -> Go -> message.hard_bounced webhook" || fail "no hard_bounced webhook"
[[ "$(drv message "$M0" | jget "'hard_bounce' in d['events']")" == True ]] && pass "the bounce event is also visible by polling the API" || fail "bounce not in events"
[[ "$(sql "SELECT count(*) FROM suppressions WHERE reason = 'hard_bounce' AND lifted_at IS NULL AND address_or_domain = (SELECT recipient_address FROM messages WHERE id = '$M0')")" == 1 ]] \
  && pass "global hard-bounce suppression (Smarthost state, not the client's)" || fail "suppression"
p5 inject arf-abuse.eml "$M1" fbl >/dev/null
[[ "$(drv await message.complained "$M1" 120 | jget "d['ok']")" == True ]] && pass "ARF -> Go -> message.complained webhook" || fail "no complained webhook"

echo "== O explicit global opt-out"
before="$(drv state | jget "d['effects']")"
[[ "$(drv opt-out "dnc.$T@example.org" | jget "d['code']")" == 201 ]] && pass "recipient global opt-out accepted for the trusted client" || fail "opt-out"
sleep 8
[[ "$(drv state | jget "d['effects']")" == "$before" ]] && pass "a suppression produces no webhook of its own" || fail "unexpected webhook after opt-out"

echo "== W webhook.test names exactly one endpoint"
before="$(drv state | jget "d['receipts']")"
w="$(drv test-webhook-without-id)"
[[ "$(jget "d['code']" <<<"$w")" == 422 ]] && pass "a request without webhook_endpoint_id is rejected (422 validation-error)" || fail "missing id: $w"
sleep 6
[[ "$(drv state | jget "d['receipts']")" == "$before" ]] && pass "and nothing is delivered (no fan-out to every endpoint)" || fail "a request without an id was delivered"

echo "== F1 receiver offline, then back"
podman stop -t 2 "$RECV" >/dev/null
e1="$(drv test-webhook | jget "d['event_id']")"
waitsql "SELECT (status = 'pending' AND attempt_count >= 1 AND last_error IS NOT NULL)::text FROM webhook_deliveries WHERE webhook_event_id = '$e1'" true 60 \
  && pass "offline receiver: attempt failed, retry scheduled ($(sql "SELECT last_error FROM webhook_deliveries WHERE webhook_event_id = '$e1'"))" || fail "no retry scheduled"
podman start "$RECV" >/dev/null
[[ "$(drv await-id "$e1" 180 | jget "d['ok']")" == True ]] && pass "delivered after the receiver came back" || fail "not delivered after restart"
waitsql "SELECT status FROM webhook_deliveries WHERE webhook_event_id = '$e1'" delivered 30 && pass "delivery recorded as delivered" || fail "delivery state"

echo "== F2 worker killed after sending, before recording"
drv control script "{\"endpoint\": \"$NAME\", \"responses\": [\"delay:20\"]}" >/dev/null
wid="$(podman inspect smarthost-webhook-worker --format '{{.Id}}')"
e2="$(drv test-webhook | jget "d['event_id']")"
[[ "$(drv received "$e2" 60 | jget "d['ok']")" == True ]] && pass "request reached the receiver (which is still processing)" || fail "request not received"
podman kill --signal KILL smarthost-webhook-worker >/dev/null
# An explicit kill is a deliberate stop for Podman's on-failure policy: start the same
# container again, as the supervisor (smarthostctl start / systemd) would.
sleep 2; podman start smarthost-webhook-worker >/dev/null
waitsql "SELECT status FROM webhook_deliveries WHERE webhook_event_id = '$e2'" delivered 200 && pass "re-claimed after the lease and delivered" || fail "not delivered after the kill"
st="$(drv state)"
[[ "$(jget "d['by_event']['$e2'] >= 2 and d['effects_by_event']['$e2'] == 1" <<<"$st")" == True ]] \
  && pass "sent at least twice, one client-side effect (de-duplicated by event id)" || fail "dedupe: $st"
[[ "$(podman inspect smarthost-webhook-worker --format '{{.Id}}')" == "$wid" ]] && pass "the same worker container was started again, never replaced (D-35)" || fail "worker container replaced"
for _ in $(seq 1 60); do [[ "$(podman inspect smarthost-webhook-worker --format '{{.State.Status}}/{{.State.Health.Status}}')" == running/healthy ]] && break; sleep 2; done
[[ "$(podman inspect smarthost-webhook-worker --format '{{.State.Status}}/{{.State.Health.Status}}')" == running/healthy ]] && pass "worker running and healthy again" || fail "worker not running/healthy"

echo "== F3 permanent failure and the polling fallback"
drv control script "{\"endpoint\": \"$NAME\", \"responses\": [\"400\"]}" >/dev/null
small="$(drv send-small "$T" | jget "d['job_id']")"
waitsql "SELECT status FROM webhook_deliveries d JOIN webhook_events e ON e.id = d.webhook_event_id WHERE e.subject_id = '$small' AND e.event_type = 'send.completed'" failed 180 \
  && pass "HTTP 400 fails the delivery without retry" || fail "400 handling"
[[ "$(drv await send.completed "$small" 5 | jget "d['ok']")" == False ]] && pass "the client never received that webhook" || fail "unexpected receipt"
[[ "$(drv reconcile "$small" 60 | jget "d['status']")" == completed ]] && pass "the client reconciles the job by polling GET /v1/send-jobs/{id}" || fail "reconcile"

echo "== X SSRF: private and metadata destinations are refused"
for target in http://127.0.0.1:9/hook http://169.254.169.254/latest/meta-data; do
  ep="$(console smarthost:webhook:add "$CLIENT" "$target" --event send.failed | field webhook_endpoint_id)"
  ev_id="$(podman run --rm --network smarthost-internal --label project=smarthost -e CLIENT_API_KEY="$KEY" --entrypoint python "$TOOLS" -c "
import json,ssl,urllib.request
c=ssl.create_default_context(); c.check_hostname=False; c.verify_mode=ssl.CERT_NONE
r=urllib.request.Request('https://symfony-app/v1/webhooks/test', data=json.dumps({'webhook_endpoint_id':'$ep'}).encode(), method='POST',
  headers={'Authorization':'Bearer '+__import__('os').environ['CLIENT_API_KEY'],'Content-Type':'application/json','Host':'localhost'})
print(json.load(urllib.request.urlopen(r, context=c))['webhook_event_id'])")"
  waitsql "SELECT status || ':' || coalesce(last_response_status::text, '-') FROM webhook_deliveries WHERE webhook_event_id = '$ev_id'" "failed:-" 60 \
    && pass "$target refused before any request ($(sql "SELECT last_error FROM webhook_deliveries WHERE webhook_event_id = '$ev_id'"))" || fail "$target not refused"
  console smarthost:webhook:set-status "$ep" disabled >/dev/null
done

echo "== receiver-side checks"
st="$(podman exec "$RECV" python -c "import json; s=json.load(open('/data/state.json')); r=[x for x in s['receipts'] if x['endpoint'] == '$NAME']; print(json.dumps({'rejected': len([x for x in s['rejected'] if x['endpoint'] == '$NAME']), 'ua': sorted({x['user_agent'] for x in r}), 'valid': all(x['signature_valid'] for x in r), 'requests': len(r)}))")"
[[ "$(jget "d['rejected'] == 0 and d['valid']" <<<"$st")" == True ]] && pass "every request carried a valid signature" || fail "signature failures: $st"
VERSION="$(sed -n "s/.*const VERSION = '\(.*\)';/\1/p" "$REPO/app/src/Version.php")"
[[ "$(jget "d['ua'] == ['Catto-Mail-Smarthost/$VERSION']" <<<"$st")" == True ]] && pass "User-Agent Catto-Mail-Smarthost/$VERSION" || fail "user agent: $st"
[[ "$(podman inspect smarthost-webhook-worker --format '{{join .Config.Cmd " "}}')" == *"smarthost:webhook:work"* ]] && pass "the worker container runs smarthost:webhook:work (no probe)" || fail "worker command"
[[ "$(sql "SELECT count(*) FROM webhook_worker_heartbeats WHERE stopped_at IS NULL AND last_seen_at > now() - interval '1 minute'")" -ge 1 ]] && pass "worker heartbeat recorded" || fail "heartbeat"

echo "phase7-e2e: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
