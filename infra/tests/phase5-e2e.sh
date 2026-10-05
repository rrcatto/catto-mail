#!/usr/bin/env bash
# Smarthost Phase 5 end-to-end test against the running `smarthost` pod:
#
#   /v1 -> Go -> Postfix :587 -> OpenDKIM -> Mailpit        (the original messages)
#   synthetic DSN / ARF -> Postfix :25 -> virtual(8) -> DSN Maildir spool
#   -> Go claim -> correlation -> message event -> projection -> global suppression
#
# Scenarios (all mail stays on the internal network):
#   A  cross-client hard bounce: client A's recipient-specific 5.1.1 DSN suppresses
#      the address globally; client B's later message is suppressed, never submitted
#   B  trusted-client global opt-out: 403 without the capability; client A's opt-out
#      suppresses client B's send; idempotent, also after a lift (D-38); lifting it
#      restores sending; a suspended trusted client may create but not lift (D-37)
#   C  correlation by ENVID, returned Message-ID and Postfix queue id; recipient+sender
#      evidence alone stays an unmatched DSN with an operator candidate (D-36)
#   D  ARF complaint to the feedback-loop address: complained, global suppression,
#      message.complained in the outbox
#   E  excluded scopes (provider policy, domain, DELAY) never suppress; three
#      recipient soft bounces across two clients create a temporary suppression
#   F  unmatched DSN -> operator match (console) -> Go resolution; dismissal with reason
#   G  crash/restart: claims left in processing/ and a worker SIGKILLed mid-batch;
#      every DSN recorded exactly once; processed-spool retention deletes old files only
#
# Usage: infra/tests/phase5-e2e.sh [A B C D E F G]
set -uo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GEN="$REPO/infra/.generated"
TOOLS=localhost/smarthost-testtools:dev
SCEN="${*:-A B C D E F G}"
PASS=0; FAIL=0
ev() { grep -E "^$1=" "$REPO/infra/.env" | head -n1 | cut -d= -f2-; }
pass() { PASS=$((PASS+1)); echo "  PASS  $*"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL  $*"; }
jget() { python3 -c "import json,sys; d=json.loads(sys.stdin.read().strip().splitlines()[-1]); print(eval(sys.argv[1], {}, {'d': d}))" "$1"; }
SPOOL="$(ev SMARTHOST_DSN_SPOOL_DIR)"
UIDGID="$(ev SMARTHOST_DELIVERY_UID):$(ev SMARTHOST_SPOOL_GID)"

podman pod exists smarthost && [[ "$(podman pod inspect smarthost --format '{{.State}}')" == Running ]] \
  || { echo "phase5-e2e: the smarthost pod is not running (smarthostctl start)" >&2; exit 2; }
"$GEN/podman/smarthost-pod.sh" wait-healthy 300 >/dev/null || { echo "phase5-e2e: services not healthy" >&2; exit 2; }
podman build -q -t "$TOOLS" "$REPO/infra/tests" >/dev/null

console() { podman exec -u www-data smarthost-symfony-app php bin/console "$@"; }
field() { sed -n "s/^ *$1: //p" | head -n1; }
OPERATOR="op-$(date +%s)@smarthost-dev.test"
boot="$(console smarthost:dev:bootstrap --operator-email "$OPERATOR" 2>/dev/null)"
KEY_A="$(field api_key <<<"$boot")"; CLIENT_A="$(field client_id <<<"$boot")"
[[ -n "$KEY_A" ]] || { echo "phase5-e2e: could not obtain a development API key" >&2; exit 2; }
drv() {
  podman run --rm --network smarthost-internal --label project=smarthost -v "$REPO/delivery/internal/dsn/testdata:/fixtures:ro" \
    -e SMARTHOST_DB_NAME="$(ev SMARTHOST_DB_NAME)" -e SMARTHOST_DB_OWNER_USER="$(ev SMARTHOST_DB_OWNER_USER)" \
    -e SMARTHOST_DB_OWNER_PASSWORD="$(ev SMARTHOST_DB_OWNER_PASSWORD)" -e SMARTHOST_BOUNCE_DOMAIN="$(ev SMARTHOST_BOUNCE_DOMAIN)" \
    --entrypoint python "$TOOLS" /opt/tests/phase5_e2e.py "$@"
}
sql() { drv sql "$1" | jget "d['rows'][0][0] if d['rows'] else ''"; }
waitsql() { [[ "$(drv wait-sql "$1" "$2" "${3:-60}" | jget "d['ok']")" == True ]]; }
# send KEY addr... -> prints "id status" per message
send() { drv send "$@" | jget "'\n'.join(m['id'] + ' ' + m['status'] + ' ' + m['queue_id'] for m in d['messages'])"; }
first_id() { cut -d' ' -f1 <<<"$1" | head -n1; }
sups() { sql "SELECT count(*) FILTER (WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > now()))::text || ':' || coalesce(string_agg(reason, ',' ORDER BY reason) FILTER (WHERE lifted_at IS NULL), '') FROM suppressions WHERE address_or_domain = '$1'"; }
postfix_saw() { podman exec smarthost-postfix grep -c "message-id=<$1@" "$(ev SMARTHOST_POSTFIX_OBSERVABILITY_DIR)/log/postfix.log" 2>/dev/null || true; }

# Client B: a second, ordinary client (no global-opt-out capability) sending from
# the development domain.
CLIENT_B="$(console smarthost:client:create --company "Phase5 E2E B $(date +%s)" --contact-email b@smarthost-dev.test --status active | field client_id)"
console smarthost:domain:add "$CLIENT_B" smarthost-dev.test >/dev/null
sql "UPDATE sending_domains SET status = 'verified', verified_at = now(), dkim_selector = 'phase1', dkim_status = 'active' WHERE client_id = '$CLIENT_B' RETURNING 'ok'" >/dev/null
KEY_B="$(console smarthost:api-key:create "$CLIENT_B" --name e2e | field api_key)"
T="$(date +%s)"

if [[ " $SCEN " == *" A "* ]]; then
  echo "== A cross-client hard bounce (real Postfix :25 and spool)"
  addr="Hard.Bounce.$T@Example.ORG"; norm="Hard.Bounce.$T@example.org"
  a="$(first_id "$(send "$KEY_A" "$addr")")"
  [[ "$(sql "SELECT current_status FROM messages WHERE id = '$a'")" == remote_accepted ]] && pass "client A message remote_accepted via Mailpit" || fail "client A message not accepted"
  drv inject postfix-hard-5.1.1.eml "$a" >/dev/null
  waitsql "SELECT current_status FROM messages WHERE id = '$a'" hard_bounced 60 && pass "DSN through Postfix :25 -> spool -> Go: remote_accepted superseded by hard_bounced" || fail "DSN not applied"
  [[ "$(sql "SELECT count(*) FROM message_events WHERE message_id = '$a' AND event_type = 'hard_bounce' AND event_source = 'dsn_spool' AND failure_scope = 'recipient' AND metadata_json->>'correlation' = 'verp'")" == 1 ]] \
    && pass "one hard_bounce event (dsn_spool, VERP-correlated, failure_scope recipient)" || fail "hard_bounce event"
  [[ "$(sql "SELECT count(*) FROM webhook_events WHERE subject_id = '$a' AND event_type = 'message.hard_bounced'")" == 1 ]] && pass "message.hard_bounced in the outbox once" || fail "outbox"
  [[ "$(sql "SELECT count(*) FROM suppressions WHERE address_or_domain = '$norm' AND client_id IS NULL AND reason = 'hard_bounce' AND source_message_id = '$a' AND source_event_id IS NOT NULL AND lifted_at IS NULL AND expires_at IS NULL")" == 1 ]] \
    && pass "global hard_bounce suppression with source message and event" || fail "suppression: $(sups "$norm")"
  b="$(first_id "$(send "$KEY_B" "Hard.Bounce.$T@EXAMPLE.org")")"
  [[ "$(sql "SELECT current_status FROM messages WHERE id = '$b'")" == suppressed && "$(postfix_saw "$b")" == 0 ]] \
    && pass "client B's message to the same address is suppressed and never reached Postfix" || fail "client B not suppressed"
  key="$(sql "SELECT source_event_key FROM message_events WHERE message_id = '$a' AND event_source = 'dsn_spool'")"
  podman exec smarthost-postfix test -f "$SPOOL/done/$key" && pass "processed spool file retained in done/$key" || fail "spool file not in done/"
fi

if [[ " $SCEN " == *" B "* ]]; then
  echo "== B trusted-client recipient global opt-out"
  addr="optout.$T@example.net"
  [[ "$(drv optout "$KEY_B" "$addr" | jget "d['code']")" == 403 ]] && pass "client without the capability: 403" || fail "untrusted opt-out not refused"
  idem="e2e-$T-optout"
  o1="$(drv optout "$KEY_A" "  $addr " "$idem")"; id="$(jget "d['body']['id']" <<<"$o1")"
  [[ "$(jget "d['code']" <<<"$o1")" == 201 && "$(jget "d['body']['reason']" <<<"$o1")" == recipient_global_opt_out ]] && pass "trusted client A reports the opt-out (201)" || fail "opt-out: $o1"
  [[ "$(drv optout "$KEY_A" "  $addr " "$idem" | jget "(d['code'], d['body']['id'])")" == "(201, '$id')" ]] && pass "idempotent replay (same key, same body) returns the same opt-out" || fail "replay"
  [[ "$(drv optout "$KEY_A" "$addr" | jget "(d['code'], d['body']['id'])")" == "(200, '$id')" ]] && pass "a new key for an already opted-out address returns the existing row (200)" || fail "existing opt-out"
  [[ "$(sql "SELECT (client_id IS NULL)::text || ':' || source_client_id FROM suppressions WHERE id = '$id'")" == "true:$CLIENT_A" ]] && pass "global row; provenance source_client_id = client A" || fail "provenance"
  b="$(first_id "$(send "$KEY_B" "$addr")")"
  [[ "$(sql "SELECT current_status FROM messages WHERE id = '$b'")" == suppressed ]] && pass "client B's send to the opted-out address is suppressed" || fail "opt-out not global"
  [[ "$(drv lift "$KEY_B" "$id" | jget "d['code']")" == 403 ]] && pass "client B cannot lift client A's opt-out" || fail "foreign lift"
  [[ "$(drv lift "$KEY_A" "$id" | jget "d['body']['status']")" == lifted ]] && pass "client A lifts its opt-out (row kept, audited)" || fail "lift"
  [[ "$(sql "SELECT count(*) FROM audit_log WHERE target_id = '$id' AND action IN ('suppression.global_opt_out_created', 'suppression.global_opt_out_lifted')")" == 2 ]] && pass "creation and lift audited" || fail "audit"
  b2="$(first_id "$(send "$KEY_B" "$addr")")"
  [[ "$(sql "SELECT current_status FROM messages WHERE id = '$b2'")" == remote_accepted ]] && pass "after the lift client B can send again" || fail "still suppressed after lift"
  # D-38: the key that found the opt-out active keeps its meaning after the lift.
  [[ "$(drv optout "$KEY_A" "$addr" "$idem-b" | jget "d['code']")" == 201 ]] && pass "a new key after the lift is a new report: a new opt-out (201)" || fail "new key after lift"
  id2="$(sql "SELECT id FROM suppressions WHERE address_or_domain = '$addr' AND lifted_at IS NULL")"
  [[ "$(drv optout "$KEY_A" "$addr" "$idem-c" | jget "(d['code'], d['body']['id'])")" == "(200, '$id2')" ]] && pass "a second key for the active opt-out returns it (200)" || fail "second key"
  drv lift "$KEY_A" "$id2" >/dev/null
  n_before="$(sql "SELECT count(*) FROM suppressions WHERE address_or_domain = '$addr'")"
  [[ "$(drv optout "$KEY_A" "$addr" "$idem-c" | jget "(d['code'], d['body']['id'], d['body']['status'])")" == "(200, '$id2', 'lifted')" \
     && "$(sql "SELECT count(*) FROM suppressions WHERE address_or_domain = '$addr'")" == "$n_before" ]] \
    && pass "after the lift, retrying that key replays its result and creates no suppression (durable idempotency)" || fail "key meaning changed after lift"
  # D-37: a suspended trusted client may create an opt-out but not lift one.
  CLIENT_T="$(console smarthost:client:create --company "Phase5 E2E trusted $T" --contact-email t@smarthost-dev.test --status active | field client_id)"
  console smarthost:client:global-suppressions "$CLIENT_T" enable --operator "$OPERATOR" --note "e2e trusted client" >/dev/null
  KEY_T="$(console smarthost:api-key:create "$CLIENT_T" --name e2e | field api_key)"
  console smarthost:client:set-status "$CLIENT_T" suspended >/dev/null
  ot="$(drv optout "$KEY_T" "suspended.$T@example.net")"; tid="$(jget "d['body'].get('id', '')" <<<"$ot")"
  [[ "$(jget "d['code']" <<<"$ot")" == 201 ]] && pass "a suspended trusted client creates an opt-out (do-not-contact)" || fail "suspended create: $ot"
  [[ "$(drv lift "$KEY_T" "$tid" | jget "d['code']")" == 403 ]] && pass "a suspended trusted client cannot lift it" || fail "suspended lift"
  console smarthost:client:set-status "$CLIENT_T" active >/dev/null
  [[ "$(drv lift "$KEY_T" "$tid" | jget "d['code']")" == 200 ]] && pass "once active again it can lift its own opt-out" || fail "active lift"
fi

if [[ " $SCEN " == *" C "* ]]; then
  echo "== C correlation through the real spool"
  for mode in envid msgid qid; do
    fx=postfix-hard-5.1.1.eml; [[ "$mode" == envid ]] && fx=missing-optional-fields.eml; [[ "$mode" == qid ]] && fx=queue-id-only.eml
    [[ "$mode" == msgid ]] && fx=message-id-only.eml
    m="$(first_id "$(send "$KEY_A" "corr-$mode-$T@example.com")")"
    drv inject "$fx" "$m" "$mode" >/dev/null
    want="$mode"; [[ "$mode" == envid ]] && want=envelope_id; [[ "$mode" == msgid ]] && want=message_id; [[ "$mode" == qid ]] && want=queue_id
    waitsql "SELECT metadata_json->>'correlation' FROM message_events WHERE message_id = '$m' AND event_source = 'dsn_spool'" "$want" 60 \
      && pass "correlated by $want" || fail "correlation by $want"
  done
  # D-36: a (forgeable) DSN to postmaster@ with only the recipient and the real sender.
  m="$(first_id "$(send "$KEY_A" "corr-recipient-$T@example.com")")"
  drv inject message-id-only.eml "$m" recipient >/dev/null
  waitsql "SELECT count(*) FROM unmatched_dsns WHERE final_recipient = 'corr-recipient-$T@example.com' AND status = 'open' AND detail_json->'candidates' @> '[{\"message_id\": \"$m\", \"sender_matches_returned_from\": true}]'" 1 60 \
    && [[ "$(sql "SELECT count(*) FROM message_events WHERE message_id = '$m' AND event_source = 'dsn_spool'")" == 0 \
          && "$(sql "SELECT current_status FROM messages WHERE id = '$m'")" == remote_accepted && "$(sups "corr-recipient-$T@example.com")" == "0:" ]] \
    && pass "recipient + sender evidence: open unmatched DSN with the message as operator candidate; no event, no suppression" \
    || fail "recipient + sender evidence must not correlate"
fi

if [[ " $SCEN " == *" D "* ]]; then
  echo "== D ARF complaint to the feedback-loop address"
  m="$(first_id "$(send "$KEY_A" "complaint.$T@example.com")")"
  drv inject arf-abuse.eml "$m" fbl >/dev/null
  waitsql "SELECT current_status FROM messages WHERE id = '$m'" complained 60 && pass "complaint correlated by the returned Message-ID: complained" || fail "complaint not applied"
  [[ "$(sql "SELECT count(*) FROM webhook_events WHERE subject_id = '$m' AND event_type = 'message.complained'")" == 1 ]] && pass "message.complained in the outbox" || fail "outbox"
  [[ "$(sups "complaint.$T@example.com")" == "1:complaint" ]] && pass "global complaint suppression" || fail "complaint suppression: $(sups "complaint.$T@example.com")"
  u="$(first_id "$(send "$KEY_A" "unknown-complaint.$T@example.com")")"
  drv inject arf-unknown-message.eml "$u" none >/dev/null
  waitsql "SELECT count(*) FROM unmatched_dsns WHERE classification = 'complaint' AND final_recipient = 'unknown-complaint.$T@example.com'" 1 60 \
    && [[ "$(sups "unknown-complaint.$T@example.com")" == "0:" ]] && pass "uncorrelated complaint kept for the operator; nobody suppressed" || fail "uncorrelated complaint"
fi

if [[ " $SCEN " == *" E "* ]]; then
  echo "== E excluded scopes and repeated soft bounces"
  for fx in provider-policy-5.7.1.eml domain-failure-5.1.2.eml delay-notice.eml; do
    m="$(first_id "$(send "$KEY_A" "scope-${fx%%.eml}-$T@example.com")")"
    drv inject "$fx" "$m" >/dev/null
    waitsql "SELECT count(*) FROM message_events WHERE message_id = '$m' AND event_source = 'dsn_spool'" 1 60
    [[ "$(sups "scope-${fx%%.eml}-$T@example.com")" == "0:" ]] && pass "$fx recorded, no suppression" || fail "$fx suppressed"
  done
  soft="soft.$T@example.com"
  for k in "$KEY_A" "$KEY_B" "$KEY_A"; do
    m="$(first_id "$(send "$k" "$soft")")"
    drv inject remote-mailbox-full-4.2.2.eml "$m" >/dev/null
    waitsql "SELECT current_status FROM messages WHERE id = '$m'" soft_bounced 60 || fail "soft bounce not applied"
    n=$((${n:-0}+1)); s="$(sups "$soft")"
    if (( n < 3 )); then [[ "$s" == "0:" ]] && pass "after $n recipient soft bounce(s): not suppressed" || fail "suppressed early: $s"; fi
  done
  [[ "$(sups "$soft")" == "1:repeated_soft_bounce" ]] && pass "third consecutive recipient soft bounce (two clients): global repeated_soft_bounce" || fail "threshold: $(sups "$soft")"
  [[ "$(sql "SELECT round(extract(epoch FROM expires_at - created_at) / 86400)::text FROM suppressions WHERE address_or_domain = '$soft'")" == 30 ]] && pass "temporary: expires after the 30-day window" || fail "lifetime"
fi

if [[ " $SCEN " == *" F "* ]]; then
  echo "== F unmatched DSN and the operator workflow"
  m="$(first_id "$(send "$KEY_A" "resolve.$T@example.com")")"
  drv inject postfix-hard-5.1.1.eml "$m" none >/dev/null
  waitsql "SELECT count(*) FROM unmatched_dsns WHERE final_recipient = 'resolve.$T@example.com' AND status = 'open'" 1 60 \
    && [[ "$(sql "SELECT current_status FROM messages WHERE id = '$m'")" == remote_accepted ]] && pass "no identifier: open unmatched DSN, no guessed event" || fail "unmatched"
  row="$(sql "SELECT id FROM unmatched_dsns WHERE final_recipient = 'resolve.$T@example.com'")"
  console smarthost:dsn:show "$row" | grep -q "resolve.$T@example.com" && pass "smarthost:dsn:show shows the parsed evidence" || fail "dsn:show"
  console smarthost:dsn:match "$row" "$m" --operator "$OPERATOR" --note "same recipient and time" >/dev/null
  waitsql "SELECT status FROM unmatched_dsns WHERE id = '$row'" matched 60 && pass "Go applied the operator's match request" || fail "match not applied"
  [[ "$(sql "SELECT count(*) FROM unmatched_dsns d JOIN message_events e ON e.id = d.resolution_event_id JOIN users u ON u.id = d.resolution_requested_by WHERE d.id = '$row' AND e.event_source = 'unmatched_dsn_resolution' AND e.message_id = '$m' AND u.email = '$OPERATOR'")" == 1 ]] \
    && [[ "$(sql "SELECT current_status FROM messages WHERE id = '$m'")" == hard_bounced && "$(sups "resolve.$T@example.com")" == "1:hard_bounce" ]] \
    && pass "resolution event, operator, projection and suppression recorded; row retained" || fail "resolution details"
  m2="$(first_id "$(send "$KEY_A" "dismiss.$T@example.com")")"
  drv inject postfix-hard-5.1.1.eml "$m2" none >/dev/null
  waitsql "SELECT count(*) FROM unmatched_dsns WHERE final_recipient = 'dismiss.$T@example.com'" 1 60
  row2="$(sql "SELECT id FROM unmatched_dsns WHERE final_recipient = 'dismiss.$T@example.com'")"
  ! console smarthost:dsn:dismiss "$row2" --operator "$OPERATOR" >/dev/null 2>&1 && console smarthost:dsn:dismiss "$row2" --operator "$OPERATOR" --reason "backscatter test" >/dev/null \
    && [[ "$(sql "SELECT status || ':' || resolution_note FROM unmatched_dsns WHERE id = '$row2'")" == "dismissed:backscatter test" ]] \
    && pass "dismissal requires a reason and keeps the row" || fail "dismissal"
fi

if [[ " $SCEN " == *" G "* ]]; then
  echo "== G crash/restart idempotency and spool retention"
  ids=(); for i in $(seq 1 3); do ids+=("$(first_id "$(send "$KEY_A" "crash$i.$T@example.com")")"); done
  podman stop smarthost-delivery >/dev/null
  for i in 0 1 2; do for _ in 1 2 3 4 5; do drv inject delay-notice.eml "${ids[$i]}" >/dev/null; done; done   # 15 DSNs
  waitsql "SELECT 1" 1 5 >/dev/null
  spoolrun() { podman run --rm --network none --label project=smarthost --user "$UIDGID" -v smarthost-dsn-spool:"$SPOOL" --entrypoint sh localhost/smarthost-delivery:dev -c "$1"; }
  inbound="$(spoolrun "ls $SPOOL/inbound/new | wc -l")"
  [[ "$inbound" -ge 15 ]] && pass "15 DSNs waiting in inbound/new while the daemon is down ($inbound)" || fail "spool has $inbound files"
  # Crashed claims: three files already renamed to processing/ (no database record).
  spoolrun "cd $SPOOL/inbound/new && for f in \$(ls | head -n 3); do mv \"\$f\" ../../processing/\"\${f%%:*}\"; done"
  # Retention: one processed file older than DELIVERY_DSN_RETENTION_DAYS.
  oldkey="$(sql "SELECT source_event_key FROM message_events WHERE event_source = 'dsn_spool' ORDER BY recorded_at DESC LIMIT 1")"
  spoolrun "test -f $SPOOL/done/$oldkey && touch -c -d '10 days ago' $SPOOL/done/$oldkey" && pass "aged the existing processed file done/$oldkey to 10 days" || fail "no processed file to age"
  # A worker with a 20 s lease is SIGKILLed mid-pass, then another one finishes.
  worker() { podman run -d --name "$1" --pod smarthost --label project=smarthost --env-file "$GEN/env/delivery.env" --user "$UIDGID" \
    -v smarthost-postfix-observability:"$(ev SMARTHOST_POSTFIX_OBSERVABILITY_DIR)":ro -v smarthost-dsn-spool:"$SPOOL" \
    -e DELIVERY_WORKER_ID="$1" -e DELIVERY_LEASE_SECONDS=20 localhost/smarthost-delivery:dev run >/dev/null; }
  worker p5-crash-a; sleep 1; podman kill --signal KILL p5-crash-a >/dev/null 2>&1; podman rm -f p5-crash-a >/dev/null
  worker p5-crash-b
  keys="'$(IFS=,; echo "${ids[*]}" | sed "s/,/','/g")'"
  waitsql "SELECT count(*) FROM message_events WHERE message_id IN ($keys) AND event_source = 'dsn_spool'" 15 120 \
    && pass "after the SIGKILL and the expiry of the stale claims, all 15 DSNs are recorded" || fail "DSNs lost: $(sql "SELECT count(*) FROM message_events WHERE message_id IN ($keys) AND event_source = 'dsn_spool'")"
  [[ "$(sql "SELECT count(*) FROM (SELECT source_event_key FROM message_events WHERE event_source = 'dsn_spool' GROUP BY 1 HAVING count(*) > 1) x")" == 0 ]] && pass "no duplicate event for any spool key" || fail "duplicate events"
  waitsql "SELECT 1" 1 3 >/dev/null
  left="$(spoolrun "find $SPOOL/inbound/new $SPOOL/processing -type f | wc -l")"
  [[ "$left" == 0 ]] && pass "inbound/new and processing/ are empty" || fail "files left: $left"
  spoolrun "test ! -e $SPOOL/done/$oldkey" && [[ "$(sql "SELECT count(*) FROM message_events WHERE source_event_key = '$oldkey'")" == 1 ]] \
    && pass "retention deleted the old processed file; its database event remains" || fail "retention"
  wlog="$(podman logs p5-crash-b 2>&1)"
  [[ "$(grep -c 'reclaimed a DSN spool file' <<<"$wlog")" -ge 1 ]] && pass "the worker reclaimed the stale processing/ claims ($(grep -c 'reclaimed a DSN spool file' <<<"$wlog"))" || fail "no reclaim logged"
  podman stop p5-crash-b >/dev/null; podman rm -f p5-crash-b >/dev/null
  podman start smarthost-delivery >/dev/null
fi

podman start smarthost-delivery >/dev/null 2>&1 || true
"$GEN/podman/smarthost-pod.sh" wait-healthy 120 >/dev/null || true
echo "phase5-e2e: $PASS passed, $FAIL failed"
(( FAIL == 0 ))
