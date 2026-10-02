#!/usr/bin/env bash
# Phase 1 verification suite (spec implementation_phases[1]; docs/development-environment.md).
#
#   infra/bin/smarthostctl verify [--clean]
#
# Proves the running development environment behaves as specified: topology,
# readiness, network isolation, mail capture, DKIM, milter failure policy,
# PostgreSQL roles, Postfix observability (V-1..V-7), DSN spool, fake SMTP and
# persistence across restart/recreation. --clean first destroys all Smarthost
# volumes and the network (disposable development data only) to prove a
# clean-state start. Containers of other projects are never touched; T22
# proves it. Exit status 0 only when every check passes.
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CTL="$REPO/infra/bin/smarthostctl"
GEN="$REPO/infra/.generated"
TOOLS=localhost/smarthost-testtools:dev
OBS=/var/lib/smarthost/postfix-observability
SPOOL=/var/lib/smarthost/dsn-spool
SERVICES=(postgres symfony-app webhook-worker nginx validator delivery postfix opendkim mailpit fake-smtp)
CLEAN=false; [[ "${1:-}" == "--clean" ]] && CLEAN=true

mkdir -p "$GEN/verify"; chmod 700 "$GEN"
LOG="$GEN/verify/phase1-$(date -u +%Y%m%dT%H%M%SZ).log"
PASS=0; FAIL=0; FAILED=()

ev() { grep -E "^$1=" "$REPO/infra/.env" | head -n1 | cut -d= -f2-; }
note() { echo "    $*" | tee -a "$LOG"; }
pass() { PASS=$((PASS+1)); echo "  PASS  $*" | tee -a "$LOG"; }
fail() { FAIL=$((FAIL+1)); FAILED+=("$*"); echo "  FAIL  $*" | tee -a "$LOG"; }
expect() { local desc="$1"; shift; if "$@" >>"$LOG" 2>&1; then pass "$desc"; else fail "$desc"; fi; }
section() { echo | tee -a "$LOG"; echo "== $*" | tee -a "$LOG"; }
json() { python3 -c "import json,sys; d=json.loads(sys.stdin.read().strip().splitlines()[-1]); print(eval(sys.argv[1], {}, {'d': d}))" "$1"; }

# Throwaway test client on the internal network (submission credentials only).
tools() {
  podman run --rm --network smarthost-internal \
    -e SMARTHOST_SUBMISSION_USERNAME="$(ev SMARTHOST_SUBMISSION_USERNAME)" \
    -e SMARTHOST_SUBMISSION_PASSWORD="$(ev SMARTHOST_SUBMISSION_PASSWORD)" \
    -e FAKE_SMTP_PORT="$(ev FAKE_SMTP_PORT)" "$@"
}
pf_log() { podman exec smarthost-postfix cat "$OBS/log/postfix.log"; }
unit_state() { "$CTL" systemctl show -p ActiveState -p SubState -p Result --value "$1" | tr '\n' ' '; }

wait_healthy() {
  local deadline=$((SECONDS + ${1:-300})) s status all
  while (( SECONDS < deadline )); do
    all=true
    for s in "${SERVICES[@]}"; do
      status="$(podman inspect "smarthost-$s" --format '{{.State.Health.Status}}' 2>/dev/null || echo missing)"
      [[ "$status" == healthy ]] || { all=false; break; }
    done
    $all && return 0
    sleep 3
  done
  for s in "${SERVICES[@]}"; do note "smarthost-$s: $(podman inspect "smarthost-$s" --format '{{.State.Status}}/{{.State.Health.Status}}' 2>/dev/null || echo missing)"; done
  return 1
}

submit() { tools "$TOOLS" submit "$1" editor@smarthost-dev.test "$2"; }
dkim_txt() { podman exec smarthost-opendkim sh -c 'cat "$OPENDKIM_KEY_DIR"/smarthost-dev.test/phase1.txt' > "$GEN/verify/dkim-public.txt"; }
dkim_verify() { tools -v "$GEN/verify/dkim-public.txt:/t/key.txt:ro" "$TOOLS" dkim-verify "$1" /t/key.txt; }
others_snapshot() { podman ps -a --format '{{.ID}} {{.Names}} {{.State}} {{.StartedAt}}' | grep -v ' smarthost-' | sort; }

echo "Smarthost Phase 1 verification — evidence log: ${LOG#$REPO/}" | tee "$LOG"
[[ -f "$REPO/infra/.env" ]] || { echo "infra/.env missing: run smarthostctl init-env" >&2; exit 2; }
OTHERS_BEFORE="$(others_snapshot)"

# ---------------------------------------------------------------------------
section "T01 images build"
expect "all Smarthost images build" "$CTL" build
expect "verification tool image builds" podman build -q -t "$TOOLS" "$REPO/infra/tests"
for img in localhost/smarthost-{postfix,opendkim,app,nginx,validator,delivery,fake-smtp}:dev \
           docker.io/library/postgres:16.15-trixie docker.io/axllent/mailpit:v1.31.0; do
  expect "image present: $img" podman image exists "$img"
done

section "T02 Quadlet render/install"
expect "render + install (Quadlet dry-run validates every unit)" "$CTL" install
for u in smarthost.target smarthost-{postgres,db-bootstrap,symfony-app,webhook-worker,nginx,validator,delivery,postfix,opendkim,mailpit,fake-smtp}.service \
         smarthost-pod.service smarthost-internal-network.service smarthost-postfix-queue-snapshot.timer smarthost-postfix-logrotate.timer; do
  st="$("$CTL" systemctl show -p LoadState --value "$u")"
  [[ "$st" == loaded ]] && pass "unit loaded: $u" || fail "unit loaded: $u ($st)"
done

if $CLEAN; then
  section "T03 clean state (disposable Smarthost data only)"
  "$CTL" stop >>"$LOG" 2>&1
  # Network/volume oneshot units are not PartOf the target; stop them so the
  # next start re-creates what is deleted below.
  "$CTL" systemctl stop smarthost-internal-network.service 'smarthost-*-volume.service' >>"$LOG" 2>&1 || true
  "$CTL" systemctl reset-failed >>"$LOG" 2>&1 || true
  expect "destroy Smarthost volumes" "$CTL" destroy-volumes --yes
  podman network rm smarthost-internal >>"$LOG" 2>&1 || true
  left="$(podman volume ls --format '{{.Name}}' | grep -c '^smarthost-' || true)"
  [[ "$left" == 0 ]] && pass "no Smarthost volumes remain" || fail "no Smarthost volumes remain ($left)"
  expect "disposable dev DKIM key generated into fresh volume" "$CTL" dkim-dev-key
fi

section "T04 start + readiness"
expect "smarthost.target starts" "$CTL" start
expect "all 10 long-running services healthy" wait_healthy 300
st="$(unit_state smarthost-db-bootstrap.service)"
[[ "$st" == "active exited success "* ]] && pass "db-bootstrap oneshot succeeded ($st)" || fail "db-bootstrap oneshot ($st)"
for t in smarthost-postfix-queue-snapshot.timer smarthost-postfix-logrotate.timer; do
  st="$(unit_state "$t")"; [[ "$st" == active* ]] && pass "timer active: $t" || fail "timer active: $t ($st)"
done
running="$(podman ps --format '{{.Names}}' | grep '^smarthost-' | sort | tr '\n' ' ')"
note "running: $running"

# ---------------------------------------------------------------------------
section "T05 pod, published ports and network"
pod_id="$(podman pod inspect smarthost --format '{{.Id}}' 2>/dev/null)"
[[ -n "$pod_id" ]] && pass "pod 'smarthost' exists" || fail "pod 'smarthost' missing"
infra_id="$(podman pod inspect smarthost --format '{{.InfraContainerID}}' 2>/dev/null)"
for s in "${SERVICES[@]}"; do
  [[ "$(podman inspect "smarthost-$s" --format '{{.Pod}}')" == "$pod_id" ]] && pass "smarthost-$s is a member of the pod" || fail "smarthost-$s not in pod"
  nm="$(podman inspect "smarthost-$s" --format '{{.HostConfig.NetworkMode}}')"
  [[ "$nm" == "container:$infra_id" ]] && pass "smarthost-$s shares the pod network namespace" || fail "smarthost-$s network mode $nm"
  [[ "$(podman inspect "smarthost-$s" --format '{{len .HostConfig.PortBindings}}')" == 0 ]] && pass "smarthost-$s publishes no port of its own" || fail "smarthost-$s has its own port bindings"
done
ports="$(podman port smarthost-infra | sort | tr '\n' ' ')"
expected="$(printf '443/tcp -> %s\n8025/tcp -> %s\n' "$(ev PROXY_HTTPS_BIND)" "$(ev MAILPIT_UI_BIND)" | sort | tr '\n' ' ')"
note "pod ports: $ports"
[[ "$ports" == "$expected" ]] && pass "the pod publishes exactly nginx HTTPS and the Mailpit UI" || fail "pod ports '$ports' (expected '$expected')"
for b in "$(ev PROXY_HTTPS_BIND)" "$(ev MAILPIT_UI_BIND)"; do
  [[ "$b" == 127.0.0.1:* ]] && pass "published bind is loopback-only: $b" || fail "published bind not loopback: $b"
done
internal="$(podman network inspect smarthost-internal --format '{{.Internal}}')"
[[ "$internal" == true ]] && pass "smarthost-internal network is Internal=true" || fail "network Internal=$internal"
nets="$(podman inspect smarthost-infra --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}')"
[[ "$nets" == "smarthost-internal " ]] && pass "the pod is attached only to smarthost-internal" || fail "pod networks: $nets"

section "T06 no Internet egress (network level)"
out="$(podman exec smarthost-postfix sh -c 'cat /proc/net/route | awk "NR>1 && \$2==\"00000000\"" | wc -l')"
[[ "$out" == 0 ]] && pass "Postfix container has no default route" || fail "Postfix has a default route"
expect "Postfix cannot open TCP to a public MX-style address (1.1.1.1:25)" bash -c '! podman exec smarthost-postfix nc -z -w 5 1.1.1.1 25'
expect "Postfix cannot resolve a public MX host" bash -c '! podman exec smarthost-postfix getent hosts gmail-smtp-in.l.google.com'
out="$(tools --entrypoint python "$TOOLS" -c 'import socket
try:
    socket.create_connection(("1.1.1.1", 25), timeout=5); print("CONNECTED")
except OSError as e: print("BLOCKED", e)')"
note "internal-network client to 1.1.1.1:25: $out"
[[ "$out" == BLOCKED* ]] && pass "any container on smarthost-internal is blocked from the Internet" || fail "egress possible: $out"

section "T07 live-mode safety guard"
guard() {  # expect refusal (exit 64 + FATAL)
  local desc="$1"; shift
  out="$(podman run --rm --network none --env-file "$GEN/env/postfix.env" "$@" localhost/smarthost-postfix:dev 2>&1)"; rc=$?
  note "$desc -> rc=$rc $(tail -n1 <<<"$out")"
  [[ $rc == 64 && "$out" == *FATAL* ]] && pass "refused: $desc" || fail "not refused: $desc"
}
guard "live mode with SMARTHOST_ENV=development" -e SMARTHOST_LIVE_DELIVERY_ENABLED=true
guard "live mode with SMARTHOST_ENV=test" -e SMARTHOST_LIVE_DELIVERY_ENABLED=true -e SMARTHOST_ENV=test
guard "capture mode without relayhost" -e POSTFIX_RELAYHOST=
guard "live mode in production with a relayhost still set" -e SMARTHOST_LIVE_DELIVERY_ENABLED=true -e SMARTHOST_ENV=production
guard "invalid switch value" -e SMARTHOST_LIVE_DELIVERY_ENABLED=yes
expect "running Postfix reports capture mode" bash -c "podman logs smarthost-postfix 2>&1 | grep -q 'CAPTURE MODE'"
out="$(podman exec smarthost-validator python -c 'import smtplib
s = smtplib.SMTP("127.0.0.1", 25, timeout=10); s.ehlo("pod-member.test"); s.mail("x@pod-member.test")
print(s.rcpt("someone@example.com")[0])')"
note "pod member -> 127.0.0.1:25 RCPT someone@example.com: $out"
[[ "$out" == 554 ]] && pass "a pod member cannot relay through Postfix port 25 via localhost (554)" || fail "port 25 relay from pod loopback: $out"
rh="$(podman exec smarthost-postfix postconf -h relayhost)"
[[ "$rh" == "$(ev POSTFIX_RELAYHOST)" ]] && pass "effective relayhost is $rh" || fail "relayhost is '$rh'"

# ---------------------------------------------------------------------------
section "T08 nginx -> FastCGI -> PHP-FPM"
body="$(curl -sk --max-time 10 "https://$(ev PROXY_HTTPS_BIND)/healthz")"
note "GET /healthz: $body"
[[ "$body" == *'"status":"ok"'* && "$body" == *'"sapi":"fpm-fcgi"'* ]] && pass "/healthz served by PHP-FPM through nginx" || fail "/healthz: $body"
code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 "https://$(ev PROXY_HTTPS_BIND)/")"
[[ "$code" == 404 ]] && pass "no application routes in Phase 1 (/ -> 404)" || fail "/ returned $code"
fpm_back() {
  "$CTL" systemctl restart smarthost-symfony-app.service || return 1
  for _ in $(seq 1 30); do curl -sk --max-time 5 "https://$(ev PROXY_HTTPS_BIND)/healthz" | grep -q fpm-fcgi && return 0; sleep 1; done
  return 1
}
expect "PHP-FPM restart (new container/IP) is picked up by nginx without restarting nginx" fpm_back

section "T09 PostgreSQL 16, roles and isolation"
ver="$(podman exec -e PGPASSWORD="$(ev POSTGRES_PASSWORD)" smarthost-postgres psql -h 127.0.0.1 -U "$(ev POSTGRES_USER)" -d "$(ev SMARTHOST_DB_NAME)" -Atc 'show server_version')"
[[ "$ver" == 16.* ]] && pass "PostgreSQL server version $ver" || fail "server version '$ver'"
out="$(podman run --rm --network smarthost-internal --env-file "$GEN/env/bootstrap.env" "$TOOLS" pg-roles)"; note "$out"
[[ "$(json "d['result']" <<<"$out")" == ok ]] && pass "five roles: only the owner can create tables; none is superuser/createdb/createrole; wrong password refused" || fail "role privileges: $out"
expect "Symfony app role (in-container probe)" podman exec smarthost-symfony-app php /srv/probe/db-check.php app
expect "Symfony webhook role (in-container probe)" podman exec smarthost-webhook-worker php /srv/probe/db-check.php webhook
expect "owner role can create tables (migrations role)" podman exec smarthost-symfony-app php /srv/probe/db-check.php owner
expect "validator role (in-container probe)" podman exec smarthost-validator python /opt/smarthost/phase1_probe.py check-db
expect "delivery role (in-container probe)" podman exec smarthost-delivery smarthost-delivery-probe check-db
pgip="$(podman inspect smarthost-infra --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}')"
out="$(podman run --rm --network podman --entrypoint python "$TOOLS" -c "import socket
try:
    socket.create_connection(('$pgip', 5432), timeout=5); print('CONNECTED')
except OSError as e: print('BLOCKED', e)")"
note "from another Podman network to $pgip:5432: $out"
[[ "$out" == BLOCKED* ]] && pass "PostgreSQL unreachable from other Podman networks" || fail "PostgreSQL reachable: $out"

# ---------------------------------------------------------------------------
section "T10 development mail reaches Mailpit, never an Internet MX"
SUBJ="phase1-verify-mail-$(date +%s)"
out="$(submit "$SUBJ" someone@example.com)"; note "$out"
QID="$(json "d.get('queue_id') or ''" <<<"$out")"
[[ -n "$QID" ]] && pass "authenticated submission on 587 accepted (queue id $QID)" || fail "submission: $out"
found="$(tools "$TOOLS" mailpit-find "$SUBJ")"; note "${found:0:300}"
[[ "$(json "d['result']" <<<"$found")" == found ]] && pass "message to someone@example.com arrived in Mailpit" || fail "not in Mailpit"
lines="$(pf_log | grep " $QID: to=")"; note "$lines"
[[ "$lines" == *"relay=mailpit["*"status=sent"* ]] && pass "Postfix relayed it to mailpit" || fail "unexpected relay: $lines"
[[ "$(grep -c 'relay=' <<<"$lines")" == "$(grep -c 'relay=mailpit\[' <<<"$lines")" ]] && pass "no delivery attempt to any other host for $QID" || fail "other relay attempted for $QID"

section "T11 DKIM signing by OpenDKIM"
dkim_txt
out="$(dkim_verify "$SUBJ")"; note "$out"
[[ "$(json "d['result']" <<<"$out")" == valid && "$(json "d['d']" <<<"$out")" == smarthost-dev.test ]] && pass "Mailpit copy carries a cryptographically valid DKIM signature (d=smarthost-dev.test, s=phase1)" || fail "DKIM: $out"
expect "OpenDKIM logged the signature for $QID" bash -c "podman logs smarthost-opendkim 2>&1 | grep -q '$QID: DKIM-Signature field added'"

section "T12 DKIM private key isolation"
for s in "${SERVICES[@]}"; do
  mounts="$(podman inspect "smarthost-$s" --format '{{range .Mounts}}{{.Name}} {{end}}')"
  if [[ "$s" == opendkim ]]; then
    [[ "$mounts" == *smarthost-opendkim-keys* ]] && pass "opendkim mounts the key volume" || fail "opendkim lacks key volume"
  else
    [[ "$mounts" != *smarthost-opendkim-keys* ]] && pass "smarthost-$s does not mount the DKIM key volume" || fail "smarthost-$s mounts DKIM keys"
  fi
done
kd="$(ev OPENDKIM_KEY_DIR)"
expect "Symfony cannot see $kd" bash -c "! podman exec smarthost-symfony-app test -e '$kd'"
expect "Go delivery cannot see $kd" bash -c "! podman exec smarthost-delivery ls '$kd'"
expect "Python validator cannot see $kd" bash -c "! podman exec smarthost-validator test -e '$kd'"
expect "private key is 0600 opendkim inside OpenDKIM" bash -c "podman exec smarthost-opendkim stat -c '%a %U' '$kd/smarthost-dev.test/phase1.private' | grep -qx '600 opendkim'"
expect "no private key material in the repository" bash -c "! git -C '$REPO' grep -q 'BEGIN .*PRIVATE KEY' -- ."

section "T13 OpenDKIM unavailable -> temporary failure, never unsigned mail (V-6)"
"$CTL" systemctl stop smarthost-opendkim.service >>"$LOG" 2>&1
SUBJ_DOWN="phase1-verify-milterdown-$(date +%s)"
out="$(submit "$SUBJ_DOWN" someone@example.com)"; note "$out"
code="$(json "d.get('code', 0)" <<<"$out")"
[[ "$(json "d['result']" <<<"$out")" == rejected && "$code" == 4* ]] && pass "submission refused with temporary $code while OpenDKIM is down" || fail "submission while milter down: $out"
expect "Postfix logged milter-reject 4.7.1 tempfail" bash -c "podman exec smarthost-postfix grep -q 'milter-reject: CONNECT.*451 4.7.1' $OBS/log/postfix.log"
sleep 3
[[ "$(json "d['result']" <<<"$(tools "$TOOLS" mailpit-find "$SUBJ_DOWN")")" == not-found ]] && pass "no unsigned message reached Mailpit" || fail "unsigned message delivered"
out="$(tools "$TOOLS" inbound "bounce+milterdown@$(ev SMARTHOST_BOUNCE_DOMAIN)" verify-milterdown)"; note "$out"
[[ "$(json "d['result']" <<<"$out")" == accepted ]] && pass "inbound port 25 DSN unaffected (milter is submission-only)" || fail "inbound while milter down: $out"
"$CTL" systemctl start smarthost-opendkim.service >>"$LOG" 2>&1
expect "OpenDKIM restored and healthy" wait_healthy 120
SUBJ_BACK="phase1-verify-milterback-$(date +%s)"
submit "$SUBJ_BACK" someone@example.com >>"$LOG"; sleep 2
[[ "$(json "d['result']" <<<"$(dkim_verify "$SUBJ_BACK")")" == valid ]] && pass "normal signed operation resumes" || fail "signing after restore"

# ---------------------------------------------------------------------------
section "T14 queue snapshots: postqueue -j (V-3)"
"$CTL" systemctl stop smarthost-mailpit.service >>"$LOG" 2>&1
SUBJ_Q="phase1-verify-queued-$(date +%s)"
QQ="$(json "d.get('queue_id') or ''" <<<"$(submit "$SUBJ_Q" queued@example.com)")"; sleep 4
expect "snapshot service runs (timer target)" "$CTL" systemctl start smarthost-postfix-queue-snapshot.service
newest="$(podman exec smarthost-postfix sh -c "ls -1 $OBS/queue/snapshot-*.jsonl | tail -n1")"
snap="$(podman exec smarthost-postfix cat "$newest")"; note "$newest: $snap"
python3 -c "import json,sys
recs=[json.loads(l) for l in sys.stdin.read().splitlines() if l.strip()]
need={'queue_name','queue_id','arrival_time','message_size','forced_expire','sender','recipients'}
assert recs and all(need <= set(r) for r in recs)
assert any(r['queue_id']=='$QQ' and r['queue_name']=='deferred' for r in recs)" <<<"$snap" \
  && pass "snapshot is JSON Lines with queue_id/queue_name/recipients; deferred $QQ present" || fail "snapshot content"
expect "snapshot file is 0640 root:$(ev SMARTHOST_SPOOL_GID) (readable by the delivery group)" bash -c "podman exec smarthost-postfix stat -c '%a %u:%g' '$newest' | grep -qx '640 0:$(ev SMARTHOST_SPOOL_GID)'"
expect "no partial temp files left behind" bash -c "! podman exec smarthost-postfix sh -c 'ls -A $OBS/queue | grep -q \"^\.tmp-\"'"
expect "delivery identity reads log + newest snapshot and cannot write (read-only)" podman exec smarthost-delivery smarthost-delivery-probe check-observability
"$CTL" systemctl start smarthost-mailpit.service >>"$LOG" 2>&1; wait_healthy 120 >/dev/null
podman exec smarthost-postfix postqueue -f >>"$LOG" 2>&1; sleep 5
[[ "$(json "d['result']" <<<"$(tools "$TOOLS" mailpit-find "$SUBJ_Q")")" == found ]] && pass "deferred message delivered to Mailpit once it returned (no fallback to MX)" || fail "queued message not delivered"
"$CTL" systemctl start smarthost-postfix-queue-snapshot.service >>"$LOG" 2>&1
newest="$(podman exec smarthost-postfix sh -c "ls -1 $OBS/queue/snapshot-*.jsonl | tail -n1")"
sz="$(podman exec smarthost-postfix stat -c %s "$newest")"
[[ "$sz" == 0 ]] && pass "empty queue produces an empty snapshot file" || note "queue not empty after flush (size $sz)"

section "T15 log generation and rotation (V-1, V-2)"
before="$(podman exec smarthost-postfix stat -c '%i' "$OBS/log/postfix.log")"
first="$(podman exec smarthost-postfix head -n1 "$OBS/log/postfix.log")"
expect "logrotate service runs (postfix logrotate)" "$CTL" systemctl start smarthost-postfix-logrotate.service
after="$(podman exec smarthost-postfix stat -c '%i %a %u:%g' "$OBS/log/postfix.log")"
rot="$(podman exec smarthost-postfix sh -c "ls -1t $OBS/log/postfix.log.* | head -n1")"
note "active inode before=$before after=[$after] rotated=$rot"
[[ "${after%% *}" != "$before" ]] && pass "postlogd reopened a new active file (new inode)" || fail "active inode unchanged"
[[ "$after" == *" 640 0:$(ev SMARTHOST_SPOOL_GID)" ]] && pass "new active file is 0640 root:spool-group" || fail "new active file perms: $after"
[[ "$rot" =~ postfix\.log\.[0-9]{8}-[0-9]{6}\.gz$ ]] && pass "rotated file named with %Y%m%d-%H%M%S and gzip-compressed" || fail "rotated name: $rot"
gzfirst="$(podman run --rm --network none --user "$(ev SMARTHOST_DELIVERY_UID):$(ev SMARTHOST_SPOOL_GID)" -v smarthost-postfix-observability:/obs:ro --entrypoint python "$TOOLS" -c "import gzip;print(gzip.open('/obs/log/$(basename "$rot")','rt').readline().rstrip())")"
[[ "$gzfirst" == "$first" ]] && pass "delivery identity reads the rotated .gz; first record identifies the generation" || fail "gz first record mismatch"
gzino="$(podman exec smarthost-postfix stat -c %i "$rot")"
[[ "$gzino" != "$before" ]] && pass "compressed generation has a different inode than the original (inode alone is not a generation id)" || fail "gz kept inode"

section "T16 DSN spool: Maildir delivery and atomic claim (V-5, V-7)"
podman rm -f sh-p1-verify-inotify >/dev/null 2>&1
# Watcher runs as the delivery identity with the spool mounted read-only.
podman run -d --name sh-p1-verify-inotify --network none --user "$(ev SMARTHOST_DELIVERY_UID):$(ev SMARTHOST_SPOOL_GID)" \
  -v smarthost-dsn-spool:/spool:ro "$TOOLS" inotify /spool/inbound/new 8 >/dev/null
sleep 2
out="$(tools "$TOOLS" inbound "bounce+verify$(date +%s)@$(ev SMARTHOST_BOUNCE_DOMAIN)" verify-dsn)"; note "$out"
[[ "$(json "d['result']" <<<"$out")" == accepted ]] && pass "VERP-form bounce recipient accepted on port 25" || fail "inbound DSN: $out"
podman wait sh-p1-verify-inotify >/dev/null 2>&1; ino="$(podman logs sh-p1-verify-inotify 2>&1 | tail -n1)"; podman rm sh-p1-verify-inotify >/dev/null 2>&1
note "inotify: $ino"
[[ "$ino" == *IN_CREATE* || "$ino" == *CREATE* ]] && pass "inotify across containers sees the new file in new/ (IN_CREATE)" || fail "inotify: $ino"
files="$(podman exec smarthost-postfix sh -c "find $SPOOL/inbound/new -type f -exec stat -c '%a %u:%g %n' {} +")"; note "$files"
[[ -n "$files" && "$(grep -vc "^600 $(ev SMARTHOST_DELIVERY_UID):$(ev SMARTHOST_SPOOL_GID) " <<<"$files")" == 0 ]] && pass "DSN files are 0600 owned by the delivery UID / spool GID" || fail "DSN file ownership: $files"
expect "Maildir tmp/ is empty after delivery (only complete files in new/)" bash -c "[ -z \"\$(podman exec smarthost-postfix ls -A $SPOOL/inbound/tmp)\" ]"
expect "delivery identity reads and atomically claims every file; second claim fails with ENOENT" podman exec smarthost-delivery smarthost-delivery-probe check-spool --claim
for r in "someone@$(ev SMARTHOST_BOUNCE_DOMAIN):550" "someone@example.com:554"; do
  out="$(tools "$TOOLS" inbound "${r%:*}" verify-reject)"
  [[ "$(json "d.get('code')" <<<"$out")" == "${r##*:}" ]] && pass "port 25 rejects ${r%:*} (${r##*:})" || fail "port 25 for ${r%:*}: $out"
done

section "T17 shared identity under rootless Podman (V-4)"
expect "delivery runs as $(ev SMARTHOST_DELIVERY_UID):$(ev SMARTHOST_SPOOL_GID)" bash -c "podman exec smarthost-delivery smarthost-delivery-probe identity | grep -q '^uid=$(ev SMARTHOST_DELIVERY_UID) gid=$(ev SMARTHOST_SPOOL_GID)'"
um="$(podman inspect smarthost-postfix smarthost-delivery --format '{{.HostConfig.UsernsMode}}|{{.HostConfig.IDMappings}}' | sort -u | wc -l)"
[[ "$um" == 1 ]] && pass "Postfix and delivery share the same user-namespace mapping" || fail "different userns mappings"

section "T18 deterministic fake SMTP"
out="$(tools "$TOOLS" fake-smtp)"; note "$out"
[[ "$(json "d['result']" <<<"$out")" == ok ]] && pass "fake SMTP reproduces 250/421/450/451/550, DATA accept-and-discard and timeout" || fail "fake SMTP: $out"

# ---------------------------------------------------------------------------
section "T19 persistence across restart and container recreation"
MARK="phase1_$(date +%s)"
OWNER_PSQL=(podman exec -e PGPASSWORD="$(ev SMARTHOST_DB_OWNER_PASSWORD)" smarthost-postgres psql -h 127.0.0.1 -U "$(ev SMARTHOST_DB_OWNER_USER)" -d "$(ev SMARTHOST_DB_NAME)" -Atc)
expect "write PostgreSQL marker row" "${OWNER_PSQL[@]}" "CREATE TABLE IF NOT EXISTS phase1_verify_marker (token text); INSERT INTO phase1_verify_marker VALUES ('$MARK')"
"$CTL" systemctl stop smarthost-mailpit.service >>"$LOG" 2>&1
QH="$(json "d.get('queue_id') or ''" <<<"$(submit "phase1-verify-hold-$(date +%s)" hold@example.com)")"; sleep 3
expect "hold queued message $QH" podman exec smarthost-postfix postsuper -h "$QH"
"$CTL" systemctl start smarthost-mailpit.service >>"$LOG" 2>&1
tools "$TOOLS" inbound "bounce+persist$(date +%s)@$(ev SMARTHOST_BOUNCE_DOMAIN)" verify-persist >>"$LOG"; sleep 2
spool_before="$(podman exec smarthost-postfix sh -c "find $SPOOL -type f -printf '%m %U:%G %P\n' | sort")"
keyhash_before="$(podman exec smarthost-opendkim sh -c 'sha256sum "$OPENDKIM_KEY_DIR"/smarthost-dev.test/phase1.private | cut -c1-64')"
logino_before="$(podman exec smarthost-postfix stat -c %i "$OBS/log/postfix.log")"
ids_before="$(podman ps --format '{{.ID}} {{.Names}}' | grep ' smarthost-' | sort)"
expect "smarthost.target restart" "$CTL" restart
expect "all services healthy after restart" wait_healthy 300
ids_after="$(podman ps --format '{{.ID}} {{.Names}}' | grep ' smarthost-' | sort)"
[[ "$(comm -12 <(cut -d' ' -f1 <<<"$ids_before") <(cut -d' ' -f1 <<<"$ids_after") | wc -l)" == 0 ]] && pass "every container was recreated (no container ID survived)" || fail "some containers were not recreated"
[[ "$("${OWNER_PSQL[@]}" "SELECT count(*) FROM phase1_verify_marker WHERE token='$MARK'")" == 1 ]] && pass "PostgreSQL marker row persisted" || fail "PostgreSQL data lost"
hold="$(podman exec smarthost-postfix postqueue -j | python3 -c "import json,sys;print(any(json.loads(l)['queue_id']=='$QH' and json.loads(l)['queue_name']=='hold' for l in sys.stdin if l.strip()))")"
[[ "$hold" == True ]] && pass "Postfix queue persisted (held $QH still queued)" || fail "Postfix queue lost $QH"
spool_after="$(podman exec smarthost-postfix sh -c "find $SPOOL -type f -printf '%m %U:%G %P\n' | sort")"
[[ "$spool_before" == "$spool_after" && -n "$spool_after" ]] && pass "DSN spool files, ownership and modes persisted" || fail "DSN spool changed"
[[ "$(podman exec smarthost-postfix stat -c %i "$OBS/log/postfix.log")" == "$logino_before" ]] && pass "observability log persisted (same active generation)" || fail "observability log changed"
[[ "$(podman exec smarthost-opendkim sh -c 'sha256sum "$OPENDKIM_KEY_DIR"/smarthost-dev.test/phase1.private | cut -c1-64')" == "$keyhash_before" ]] && pass "OpenDKIM development key persisted (same fingerprint)" || fail "DKIM key changed"
podman exec smarthost-postfix postsuper -d "$QH" >>"$LOG" 2>&1
"${OWNER_PSQL[@]}" "DROP TABLE phase1_verify_marker" >>"$LOG" 2>&1
podman exec smarthost-delivery smarthost-delivery-probe check-spool --claim >>"$LOG" 2>&1

section "T20 stop / start cycle"
expect "smarthost.target stop" "$CTL" stop
left="$(podman ps --format '{{.Names}}' | grep '^smarthost-' | tr '\n' ' ')"
[[ -z "$left" ]] && pass "no Smarthost container running when stop returns" || fail "Smarthost containers still running after stop: $left"
units_up="$("$CTL" systemctl list-units --no-legend --state=active,activating,deactivating 'smarthost-*.service' | grep -v -E 'volume|network|db-bootstrap' | wc -l)"
[[ "$units_up" == 0 ]] && pass "no Smarthost service unit active after stop" || fail "$units_up service units still active after stop"
expect "smarthost.target start" "$CTL" start
expect "all services healthy after start" wait_healthy 300

section "T21 contract checks"
expect "scripts/check-contracts.py" python3 "$REPO/scripts/check-contracts.py"

section "T22 other Podman projects untouched"
OTHERS_AFTER="$(others_snapshot)"
[[ "$OTHERS_BEFORE" == "$OTHERS_AFTER" ]] && pass "non-Smarthost containers identical before/after (IDs, state, start times)" || { fail "non-Smarthost containers changed"; diff <(echo "$OTHERS_BEFORE") <(echo "$OTHERS_AFTER") >>"$LOG"; }

echo | tee -a "$LOG"
echo "Phase 1 verification: $PASS passed, $FAIL failed  (evidence: ${LOG#$REPO/})" | tee -a "$LOG"
for f in "${FAILED[@]}"; do echo "  failed: $f" | tee -a "$LOG"; done
(( FAIL == 0 ))
