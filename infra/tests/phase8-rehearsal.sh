#!/usr/bin/env bash
# catto-mail Phase 8 production rehearsal (`smarthostctl test phase8-rehearsal`).
#
# Deploys the PRODUCTION topology (infra/podman/smarthost-production.sh.in) on this
# Podman engine as the separate instance `smarthost-rehearsal`, driven only through
# `smarthostctl prod`, with SMARTHOST_ENV=production and live delivery off, but with
# SMARTHOST_EGRESS_ENABLED=false (the egress network is internal too: nothing can
# reach the Internet) and an ingress socket on loopback ports (18443, 12525). It never
# touches the development pod (checked) and removes everything it created at the end.
#
#   R  configuration: prod init-env + check, refusal of unsafe values
#   D  deployment: build (tag rehearsal), tls, install (create), start, health
#   T  topology: networks, membership, no published ports, the socket-activated
#      ingress (systemd socket units installed in the engine host), no Mailpit
#   N  client addresses (Phase 8 networking): a control shows rootless port forwarding
#      collapsing two clients to one address; through the ingress the same two
#      loopback sources stay distinct in nginx, Symfony (sign-in link IP hash, with a
#      spoofed X-Forwarded-For ignored) and Postfix; nginx restarted by systemd after
#      a crash keeps the sockets
#   H  held mode: Go claims no send job; Postfix defers outbound except the sign-in
#      sender; submission sender ownership; a queue snapshot reaches the dashboard signal
#   P  preflight: runtime/exposure/postfix/delivery PASS; TLS trust and DNS reported
#   G  live-enable refused by the activation preflight; audited pause/resume;
#      non-ADMIN operator refused
#   S  Phase 9 SaaS operations: the reputation timer, an approval with a reason (the
#      lifecycle through the console), a quota refusal through nginx (429
#      quota-exceeded), the public API documentation, the evaluation through the timer's
#      own command path
#   B  backup and restore
#   U  upgrade to a new image tag (recreate, migrations, health), D-35 stop/start
#   Z  development pod untouched; cleanup
set -uo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
I=smarthost-rehearsal
GEN="$REPO/infra/.generated/rehearsal"
ENVF="$GEN/rehearsal.env"
TOOLS=localhost/smarthost-testtools:dev
NAME=catto-rehearsal.test
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS  $*"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL  $*"; }
prod() { SMARTHOST_DOTENV="$ENVF" SMARTHOST_GENERATED="$GEN" "$REPO/infra/bin/smarthostctl" prod "$@"; }
ev() { grep -E "^$1=" "$ENVF" | head -n1 | cut -d= -f2-; }
setv() { python3 - "$ENVF" "$1" "$2" <<'PY'
import re, sys
p, k, v = sys.argv[1:]
t = open(p).read()
t = re.sub(rf"^{re.escape(k)}=.*$", lambda m: f"{k}={v}", t, count=1, flags=re.M)
open(p, "w").write(t)
PY
}
sql() { podman exec "$I-postgres" psql -X -At -U "$(ev POSTGRES_USER)" -d "$(ev SMARTHOST_DB_NAME)" -c "$1"; }
console() { podman exec -i -u www-data "$I-symfony-app" php bin/console "$@"; }
field() { sed -n "s/^ *$1: //p" | head -n1; }
waitsql() { local _; for _ in $(seq 1 "${3:-60}"); do [[ "$(sql "$1")" == "$2" ]] && return 0; sleep 1; done; return 1; }
dev_snapshot() { podman ps -a --filter label=project=smarthost --format '{{.ID}} {{.Names}} {{.State}}' | grep -v " $I-" | sort; }

cleanup() {
  local s
  prod uninstall >/dev/null 2>&1 || true   # first: the rehearsal's ingress units in the engine host
  for s in postgres opendkim postfix symfony-app webhook-worker nginx validator delivery db-bootstrap db-migrate db-grants; do
    podman rm -f --time 5 "$I-$s" >/dev/null 2>&1 || true
  done
  for s in postgres-data postfix-queue postfix-observability dsn-spool opendkim-keys opendkim-tables; do podman volume rm -f "$I-$s" >/dev/null 2>&1 || true; done
  podman rm -f "$I-control" >/dev/null 2>&1 || true
  for s in internal egress ingress; do podman network rm -f "$I-$s" >/dev/null 2>&1 || true; done
  for s in proxy-tls-cert proxy-tls-key postfix-tls-cert postfix-tls-key; do podman secret rm "$I-$s" >/dev/null 2>&1 || true; done
  for t in rehearsal rehearsal2; do
    for s in postfix opendkim app nginx validator delivery; do podman rmi "localhost/smarthost-$s:$t" >/dev/null 2>&1 || true; done
  done
  rm -rf "$GEN"
}
if podman ps -a --format '{{.Names}}' | grep -q "^$I-"; then
  echo "phase8-rehearsal: removing a previous rehearsal"
  [[ -f "$ENVF" ]] || { mkdir -p "$GEN/systemd"; printf 'SMARTHOST_ENV=production\nSMARTHOST_INSTANCE=%s\n' "$I" > "$ENVF"; }
  cleanup
fi
trap cleanup EXIT
DEV_BEFORE="$(dev_snapshot)"
mkdir -p "$GEN"; chmod 700 "$GEN"
podman build -q -t "$TOOLS" "$REPO/infra/tests" >/dev/null

echo "== R configuration"
prod init-env >/dev/null
[[ "$(stat -c %a "$ENVF")" == 600 ]] && pass "prod init-env: production template with locally generated secrets (mode 0600)" || fail "init-env mode"
if prod check >/dev/null 2>&1; then fail "the untouched template must not pass (placeholders)"; else pass "the template is refused until the operator sets real values"; fi
for kv in SMARTHOST_INSTANCE=$I SMARTHOST_IMAGE_TAG=rehearsal SMARTHOST_INTERNAL_SUBNET=10.89.30.0/24 SMARTHOST_EGRESS_SUBNET=10.89.31.0/24 \
          SMARTHOST_INGRESS_SUBNET=10.89.32.0/24 TRUSTED_PROXIES= SMARTHOST_EGRESS_ENABLED=false SMARTHOST_PUBLIC_IPV4=203.0.113.10 \
          PROXY_HTTPS_BIND=127.0.0.1:18443 POSTFIX_SMTP_BIND=127.0.0.1:12525 \
          SMARTHOST_PUBLIC_BASE_URL=https://mail.$NAME PROXY_SERVER_NAME=mail.$NAME POSTFIX_MYHOSTNAME=mta.$NAME \
          SMARTHOST_BOUNCE_DOMAIN=bounce.$NAME VALIDATOR_SMTP_HELO_HOSTNAME=mta.$NAME VALIDATOR_SMTP_MAIL_FROM=validator@bounce.$NAME \
          APP_ADMIN_EMAIL=admin@$NAME APP_MAIL_FROM=no-reply@$NAME; do
  setv "${kv%%=*}" "${kv#*=}"
done
prod check >/dev/null && pass "the rehearsal configuration satisfies the production rules" || fail "check: $(prod check 2>&1 | tail -3)"
setv APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS webhook-receiver
out="$(prod render 2>&1)"; [[ "$out" == *"APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS must be empty"* ]] && pass "unsafe value refused before anything is rendered" || fail "unsafe render: $out"
setv APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS ""
out="$("$REPO/infra/bin/smarthostctl" prod status 2>&1)"
[[ "$out" == *"not a production configuration"* ]] && pass "prod commands refuse the development configuration (infra/.env)" || fail "prod on dev config: $out"

echo "== D deployment"
prod build >/dev/null && pass "prod build: six production images tagged :rehearsal" || fail "build"
prod tls self-signed >/dev/null && pass "rehearsal TLS certificates as Podman secrets" || fail "tls"
out="$(prod install 2>&1)"; [[ "$out" == *"only its own units"* ]] && prod machine systemctl cat "$I-ingress.socket" >/dev/null 2>&1 \
  && prod machine systemctl cat "$I-reputation-evaluate.timer" >/dev/null 2>&1 \
  && pass "install: host prerequisites checked; the instance's ingress socket and service and reputation timer installed in the engine host" || fail "install: ${out: -300}"
prod start >/dev/null 2>&1 && pass "start: postgres, DB tasks (bootstrap, migrate, grants), all services healthy" || fail "start: $(prod start 2>&1 | tail -3)"

echo "== T topology"
[[ "$(podman network inspect "$I-internal" --format '{{.Internal}}')" == true && "$(podman network inspect "$I-egress" --format '{{.Internal}}')" == true \
   && "$(podman network inspect "$I-ingress" --format '{{.Internal}}')" == true ]] \
  && pass "internal and ingress networks have no route; rehearsal egress network is internal too" || fail "network flags"
member() { podman inspect "$I-$1" --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' | tr ' ' '\n' | grep -v '^$' | sort | tr '\n' ' '; }
ok=true
for s in postgres opendkim delivery; do [[ "$(member "$s")" == "$I-internal " ]] || ok=false; done
for s in symfony-app webhook-worker validator; do [[ "$(member "$s")" == "$I-egress $I-internal " ]] || ok=false; done
[[ "$(member nginx)" == "$I-ingress $I-internal " && "$(member postfix)" == "$I-egress $I-ingress $I-internal " ]] || ok=false
$ok && pass "egress only for postfix, symfony-app, webhook-worker, validator; ingress only for nginx and postfix" || fail "membership"
ports="$(for s in postgres opendkim postfix symfony-app webhook-worker nginx validator delivery; do podman port "$I-$s" 2>/dev/null | sed "s/^/$s /"; done)"
[[ -z "$ports" ]] && pass "no container publishes a port (no rootless port forwarding)" || fail "ports: $ports"
listen="$(prod machine systemctl show -p Listen --value "$I-ingress.socket" | awk '{print $1}' | sort | tr '\n' ' ')"
[[ "$(prod machine systemctl is-active "$I-ingress.socket" "$I-ingress.service" | tr '\n' ' ')" == "active active " && "$listen" == "127.0.0.1:12525 127.0.0.1:18443 " ]] \
  && pass "HTTPS and SMTP listeners belong to the systemd ingress socket; nginx runs under its service" || fail "ingress units: '$listen'"
inherited() { podman logs --since "$(podman inspect "$I-nginx" --format '{{.State.StartedAt.Format "2006-01-02T15:04:05Z07:00"}}')" "$I-nginx" 2>&1 \
  | grep -F 'using inherited sockets from "3;4;"' >/dev/null; }   # not grep -q: with pipefail an early exit fails the pipe
inherited && pass "nginx inherited both sockets from systemd (its startup notice)" || fail "nginx did not inherit the sockets"
[[ "$(podman ps -a --filter "label=smarthost.instance=$I" --format '{{.Names}}' | grep -c -E 'mailpit|fake-smtp')" == 0 ]] && pass "no Mailpit or fake SMTP in production" || fail "dev services present"

echo "== N client addresses"
console smarthost:user:create "admin@$NAME" --role ADMIN >/dev/null 2>&1 || true
console smarthost:user:create "ops@$NAME" --role OPERATOR >/dev/null 2>&1 || true
# Control: the mechanism the closeout replaced. A published port (rootlessport) shows
# the server one address for two different clients.
podman run -d --name "$I-control" --label project=smarthost --network "$I-internal" -p 127.0.0.1:18444:9000 --entrypoint python "$TOOLS" \
  -c 'import socket
s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); s.bind(("0.0.0.0", 9000)); s.listen(5)
while True:
    c, a = s.accept(); c.sendall(a[0].encode()); c.close()' >/dev/null
seen_from() { python3 - "$@" <<'PY3'
import socket, sys, time
port = int(sys.argv[1])
for src in sys.argv[2:]:
    for _ in range(20):
        try:
            s = socket.socket(); s.bind((src, 0)); s.settimeout(5); s.connect(("127.0.0.1", port)); print(s.recv(100).decode()); s.close(); break
        except OSError:
            time.sleep(0.5)
PY3
}
R="127.$((RANDOM % 190 + 64)).$((RANDOM % 250 + 1))"
ctl="$(seen_from 18444 "$R.2" "$R.3" | sort -u)"
[[ -n "$ctl" && "$(wc -l <<<"$ctl")" == 1 && "$ctl" != "$R."* ]] \
  && pass "control: rootless port forwarding collapses $R.2 and $R.3 to one address ($ctl)" || fail "control: '$ctl'"
podman rm -f "$I-control" >/dev/null
out="$(prod ingress-check 2>&1)"
[[ "$(grep -c '^FAIL' <<<"$out")" == 0 ]] && grep -q "^PASS  ingress: nginx (HTTPS, Symfony REMOTE_ADDR) sees each client's own address" <<<"$out" \
  && grep -q "^PASS  ingress: Postfix (SMTP peer) sees each client's own address" <<<"$out" \
  && pass "prod ingress-check: two loopback sources arrive distinct at nginx and Postfix ($(tail -1 <<<"$out"))" \
  || { echo "$out" | sed 's/^/        /'; fail "ingress-check"; }
# Symfony's client address: the sign-in link limiter's per-address hash, with a forged
# X-Forwarded-For that must be ignored (TRUSTED_PROXIES is empty).
signin_from() { python3 - "$1" "$2" <<'PY4'
import http.cookiejar, re, socket, ssl, sys, urllib.parse
src, email = sys.argv[1:]
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
def req(method, path, body=b"", cookie=""):
    raw = socket.create_connection(("127.0.0.1", 18443), timeout=30, source_address=(src, 0))
    t = ctx.wrap_socket(raw, server_hostname="mail.catto-rehearsal.test")
    head = (f"{method} {path} HTTP/1.0\r\nHost: mail.catto-rehearsal.test\r\nX-Forwarded-For: 198.51.100.77\r\nConnection: close\r\n"
            + (f"Cookie: {cookie}\r\n" if cookie else "")
            + (f"Content-Type: application/x-www-form-urlencoded\r\nContent-Length: {len(body)}\r\n" if body else "") + "\r\n")
    t.sendall(head.encode() + body)
    data = b""
    while chunk := t.recv(65536):
        data += chunk
    t.close()
    return data.decode(errors="replace")
page = req("GET", "/dashboard/login")
token = re.search(r'name="_csrf_token" value="([^"]+)"', page).group(1)
cookie = "; ".join(m.split(";")[0] for m in re.findall(r"(?im)^set-cookie: (.*)$", page))
r = req("POST", "/dashboard/login", urllib.parse.urlencode({"email": email, "_csrf_token": token}).encode(), cookie)
print(r.split("\r\n")[0])
PY4
}
ok=true
for src in "$R.2" "$R.3"; do
  [[ "$(signin_from "$src" "ops@$NAME")" == *" 302 "* ]] || ok=false
  got="$(sql "SELECT requested_ip_hash FROM auth_login_tokens WHERE email = 'ops@$NAME' ORDER BY created_at DESC LIMIT 1")"
  want="$(podman exec "$I-symfony-app" php -r 'echo hash_hmac("sha256", $argv[1], getenv("APP_SECRET"));' -- "$src")"
  spoof="$(podman exec "$I-symfony-app" php -r 'echo hash_hmac("sha256", $argv[1], getenv("APP_SECRET"));' -- 198.51.100.77)"
  [[ -n "$got" && "$got" == "$want" && "$got" != "$spoof" ]] || ok=false
done
$ok && pass "Symfony saw $R.2 and $R.3 as two clients (sign-in limiter keys); the forged X-Forwarded-For was ignored" || fail "application client address"
podman kill --signal KILL "$I-nginx" >/dev/null 2>&1
sleep 10   # RestartSec=5, then the health check must pass again
for _ in $(seq 1 30); do [[ "$(podman inspect "$I-nginx" --format '{{.State.Health.Status}}' 2>/dev/null)" == healthy ]] && break; sleep 2; done
out="$(prod ingress-check 2>&1)"
[[ "$(grep -c '^FAIL' <<<"$out")" == 0 ]] && inherited \
  && pass "after an nginx crash systemd restarted it with the same sockets; addresses still preserved" || { echo "$out" | grep FAIL; fail "crash recovery"; }

echo "== H held mode"
console smarthost:user:create "admin@$NAME" --role ADMIN >/dev/null 2>&1 || true
console smarthost:user:create "ops@$NAME" --role OPERATOR >/dev/null 2>&1 || true
waitsql "SELECT send_work_held AND NOT live_delivery FROM delivery_heartbeats WHERE stopped_at IS NULL ORDER BY last_seen_at DESC LIMIT 1" t 60 \
  && pass "delivery daemon reports held mode" || fail "held heartbeat"
CLIENT="$(console smarthost:client:create --company "Rehearsal client" --contact-email "c@$NAME" --status active | field client_id)"
console smarthost:domain:add "$CLIENT" "send.$NAME" >/dev/null
sql "UPDATE sending_domains SET status = 'verified', verified_at = now() WHERE domain = 'send.$NAME'" >/dev/null
prod dkim generate "send.$NAME" r1 >/dev/null && prod dkim activate "send.$NAME" r1 >/dev/null \
  && console smarthost:domain:dkim "$CLIENT" "send.$NAME" active --selector r1 >/dev/null && pass "production DKIM key generated, activated and recorded" || fail "dkim"
KEY="$(console smarthost:api-key:create "$CLIENT" --name rehearsal | field api_key)"
job="$(python3 - "$KEY" "send.$NAME" <<'PY'
import json, ssl, sys, urllib.request, uuid
key, dom = sys.argv[1:]
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
def call(method, path, body=None):
    h = {"Authorization": "Bearer " + key, "Host": "mail.catto-rehearsal.test"}
    data = None
    if body is not None:
        data = json.dumps(body).encode(); h.update({"Content-Type": "application/json", "Idempotency-Key": str(uuid.uuid4())})
    r = urllib.request.urlopen(urllib.request.Request("https://127.0.0.1:18443/v1" + path, data=data, method=method, headers=h), context=ctx, timeout=60)
    return json.loads(r.read() or b"{}")
j = call("POST", "/send-jobs", {"external_reference": "rehearsal", "message_class": "transactional", "sender_identity": {"email": "news@" + dom}})
call("POST", f"/send-jobs/{j['id']}/recipients", {"recipients": [{"external_recipient_reference": "r1", "email_address": "someone@example.org", "subject": "held", "text_body": "x"}]})
call("POST", f"/send-jobs/{j['id']}/submit")
print(j["id"])
PY
)"
sleep 12
[[ -n "$job" && "$(sql "SELECT status FROM send_jobs WHERE id = '$job'")" == queued ]] \
  && pass "send job accepted through the public API (nginx, TLS) and held: not claimed" || fail "held job: '$job' $(sql "SELECT status FROM send_jobs WHERE id = '$job'" 2>&1)"
sub() { podman run --rm --name "$I-tools" --network "$I-internal" --label project=smarthost -e SMARTHOST_SUBMISSION_USERNAME="$(ev SMARTHOST_SUBMISSION_USERNAME)" \
  -e SMARTHOST_SUBMISSION_PASSWORD="$(ev SMARTHOST_SUBMISSION_PASSWORD)" "$TOOLS" submit "$1" "news@send.$NAME" "someone@example.org" "$2"; }
out="$(sub "rehearsal-held-$(date +%s)" "bounce+r1@bounce.$NAME")"
[[ "$out" == *'"code": 450'* && "$out" == *"live delivery is not activated"* ]] \
  && pass "Postfix held mode: campaign mail is refused temporarily at submission (450 live delivery is not activated)" || fail "held: $out"
out="$(sub "rehearsal-mismatch-$(date +%s)" "editor@send.$NAME")"
[[ "$out" == *'"code": 553'* && "$out" == *"not owned by user"* ]] && pass "submission rejects an envelope sender the account does not own (553)" || fail "sender ownership: $out"
# The dashboard sign-in mail is the one sender held mode still routes for delivery (smtp
# transport, not the held retry); with no DNS in the rehearsal Postfix then gives up on it.
signin="$(python3 - "admin@$NAME" <<'PY2'
import http.cookiejar, re, ssl, sys, urllib.parse, urllib.request
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
jar = http.cookiejar.CookieJar()
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
op = urllib.request.build_opener(urllib.request.HTTPSHandler(context=ctx), urllib.request.HTTPCookieProcessor(jar), NoRedirect())
h = {"Host": "mail.catto-rehearsal.test"}
body = op.open(urllib.request.Request("https://127.0.0.1:18443/dashboard/login", headers=h), timeout=30).read().decode()
token = re.search(r'name="_csrf_token" value="([^"]+)"', body).group(1)
data = urllib.parse.urlencode({"email": sys.argv[1], "_csrf_token": token}).encode()
try:
    op.open(urllib.request.Request("https://127.0.0.1:18443/dashboard/login", data=data, headers=h), timeout=30)
except urllib.error.HTTPError as e:
    print(e.code, e.headers.get("Location", ""))
PY2
)"
[[ "$signin" == "302 "*"/dashboard/login/sent" ]] && pass "dashboard sign-in link requested in held mode" || fail "sign-in request: $signin"
pflog() { podman exec "$I-postfix" sh -c 'cat "$SMARTHOST_POSTFIX_OBSERVABILITY_DIR/log/postfix.log"' | grep "to=<admin@$NAME>"; }
for _ in $(seq 1 30); do pflog >/dev/null && break; sleep 2; done
line="$(pflog | tail -n1)"
[[ -n "$line" && "$line" != *"live delivery is not activated"* && "$line" == *"relay=none"* ]] \
  && pass "held mode routes the sign-in mail for delivery (smtp transport, not held): ${line##*status=}" || fail "sign-in mail: '$line'"
"$GEN/podman/smarthost-production.sh" postfix-exec smarthost-queue-snapshot >/dev/null
waitsql "SELECT queue_active + queue_deferred + queue_incoming >= 1 AND queue_snapshot_at > now() - interval '5 minutes' FROM delivery_heartbeats WHERE stopped_at IS NULL ORDER BY last_seen_at DESC LIMIT 1" t 60 \
  && pass "queue snapshot -> delivery heartbeat: Postfix queue depth is a durable dashboard signal" || fail "queue depth signal"

echo "== P preflight"
out="$(prod preflight --section config --section host --section runtime --section exposure --section ingress --section postfix --section delivery 2>&1)"
echo "$out" | grep -E '^(FAIL|WARN)' | sed 's/^/        /' | head -20
[[ "$(grep -c '^FAIL' <<<"$out")" == 0 ]] && pass "preflight config/host/runtime/exposure/ingress/postfix/delivery: no FAIL ($(tail -1 <<<"$out"))" || fail "preflight sections"
grep -q "^PASS  postfix: port 25 is not an open relay" <<<"$out" && pass "preflight proves port 25 refuses relaying" || fail "relay check"
grep -q "^PASS  runtime: grants current" <<<"$out" && pass "preflight compares the grants with schema.md" || fail "grants check"
tls="$(prod preflight --section tls 2>&1)"
grep -q "^FAIL  tls: nginx certificate trust" <<<"$tls" && grep -q "^PASS  tls: nginx certificate name" <<<"$tls" \
  && pass "TLS: the self-signed rehearsal certificate is reported untrusted (name and validity checked)" || fail "tls: $tls"
dns="$(prod preflight --section dns --section dkim 2>&1)"; rc=$?
[[ $rc -ne 0 && "$dns" == *"dkim: send.$NAME selector r1"* ]] && pass "DNS/DKIM: unresolvable rehearsal names are reported, not passed" || fail "dns: ${dns: -400}"
prod dns-checklist | grep -q "bounce.$NAME.  MX  10 mta.$NAME." && pass "DNS checklist generated" || fail "checklist"
prod firewall | grep -q "tcp dport { 22, 25, 443 }" && pass "firewall ruleset generated" || fail "firewall"

echo "== G delivery controls"
out="$(prod live-enable --operator "admin@$NAME" --note "rehearsal" 2>&1)"
[[ "$out" == *"activation preflight failed"* && "$(ev SMARTHOST_LIVE_DELIVERY_ENABLED)" == false ]] \
  && pass "live-enable refused by the activation preflight; live delivery stays off" || fail "live-enable: ${out: -300}"
out="$(prod pause --operator "ops@$NAME" --note "no permission" 2>&1)"
[[ "$out" == *"SYSTEM.DELIVERY.CONTROL"* && "$(podman exec "$I-postfix" postconf -h defer_transports)" == "" ]] \
  && pass "an OPERATOR without SYSTEM.DELIVERY.CONTROL cannot pause (nothing changed)" || fail "operator refused: $out"
prod pause --operator "admin@$NAME" --note "rehearsal pause" >/dev/null && [[ "$(podman exec "$I-postfix" postconf -h defer_transports)" == smtp ]] \
  && pass "pause: Postfix holds all outbound" || fail "pause"
waitsql "SELECT outbound_paused FROM delivery_heartbeats WHERE stopped_at IS NULL ORDER BY last_seen_at DESC LIMIT 1" t 60 \
  && pass "the delivery daemon sees the pause (dashboard signal)" || fail "paused heartbeat"
prod resume --operator "admin@$NAME" --note "rehearsal resume" >/dev/null && [[ -z "$(podman exec "$I-postfix" postconf -h defer_transports)" ]] \
  && pass "resume" || fail "resume"
[[ "$(sql "SELECT string_agg(action, ',' ORDER BY occurred_at) FROM audit_log WHERE action LIKE 'delivery.%'")" == "delivery.outbound_paused,delivery.outbound_resumed" ]] \
  && pass "pause and resume are audited (operator and note)" || fail "audit: $(sql "SELECT string_agg(action, ',') FROM audit_log WHERE action LIKE 'delivery.%'")"
prod ops-status | grep -q "^delivery" && pass "ops-status summary" || fail "ops-status"

echo "== S Phase 9 SaaS operations"
[[ "$(prod machine systemctl is-active "$I-reputation-evaluate.timer" 2>/dev/null)" == active ]] \
  && pass "the reputation-evaluation timer runs with the topology" || fail "reputation timer"
SAAS="$(console smarthost:client:create --company "SaaS applicant" --contact-email "ops@$NAME" | field client_id)"
# No policy version is in force in the rehearsal (APP_ACCEPTABLE_USE_POLICY_VERSION empty), so approval needs none.
console smarthost:client:set-status "$SAAS" active --operator "admin@$NAME" --note "rehearsal approval" >/dev/null 2>&1
[[ "$(sql "SELECT status || ',' || (approved_at IS NOT NULL) FROM clients WHERE id = '$SAAS'")" == "active,true" \
   && "$(sql "SELECT detail_json->>'note' FROM audit_log WHERE action = 'client.approved' AND target_id = '$SAAS'")" == "rehearsal approval" ]] \
  && pass "approval through the audited lifecycle (operator, reason)" || fail "approval"
console smarthost:client:limits "$SAAS" --set validation_jobs_per_day=1 --operator "admin@$NAME" --note "rehearsal plan" >/dev/null
SKEY="$(console smarthost:api-key:create "$SAAS" --name rehearsal | field api_key)"
quota="$(python3 - "$SKEY" <<'PY5'
import json, ssl, sys, urllib.request, uuid
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
out = []
for i in range(2):
    req = urllib.request.Request("https://127.0.0.1:18443/v1/validation-jobs", data=json.dumps({"addresses": [{"address": f"q{i}@example.org"}]}).encode(),
        method="POST", headers={"Authorization": "Bearer " + sys.argv[1], "Host": "mail.catto-rehearsal.test", "Content-Type": "application/json",
        "Idempotency-Key": str(uuid.uuid4())})
    try:
        r = urllib.request.urlopen(req, context=ctx, timeout=30); out.append(str(r.status))
    except urllib.error.HTTPError as e:
        body = json.loads(e.read()); out.append(f"{e.code} {body['type'].rsplit('/', 1)[-1]} {body.get('quota', {}).get('metric')} retry={e.headers.get('Retry-After') is not None}")
print(" | ".join(out))
PY5
)"
[[ "$quota" == "202 | 429 quota-exceeded validation_jobs retry=True" ]] && pass "quota enforced through nginx: 429 quota-exceeded with Retry-After" || fail "quota: $quota"
docs="$(python3 - <<'PY6'
import ssl, urllib.request
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
r = urllib.request.urlopen(urllib.request.Request("https://127.0.0.1:18443/docs/api/openapi.v1.yaml", headers={"Host": "mail.catto-rehearsal.test"}), context=ctx, timeout=30)
print(r.status, r.headers.get("Content-Type"), b"openapi: 3.1.0" in r.read())
PY6
)"
[[ "$docs" == "200 application/yaml; charset=utf-8 True" ]] && pass "the API contract is served publicly at /docs/api" || fail "docs: $docs"
out="$("$GEN/podman/smarthost-production.sh" app-exec php bin/console smarthost:reputation evaluate 2>&1)"
[[ "$out" == evaluated* && "$(sql "SELECT count(*) > 0 FROM client_reputation_metrics WHERE client_id = '$SAAS'")" == t ]] \
  && pass "reputation evaluation through the timer's command path: $out" || fail "evaluation: $out"

echo "== B backup and restore"
clients_before="$(sql "SELECT count(*) FROM clients")"
prod backup "$GEN/backup1" >/dev/null && [[ -s "$GEN/backup1/db.dump" && -s "$GEN/backup1/opendkim-keys.tar" && "$(stat -c %a "$GEN/backup1")" == 700 ]] \
  && pass "backup: database, DKIM keys and tables, configuration (0700)" || fail "backup"
console smarthost:client:create --company "After backup" --contact-email "x@$NAME" >/dev/null
podman exec "$I-opendkim" sh -c 'rm -rf "$OPENDKIM_KEY_DIR"/send.*'
prod restore "$GEN/backup1" --yes >/dev/null 2>&1 && [[ "$(sql "SELECT count(*) FROM clients")" == "$clients_before" ]] \
  && podman exec "$I-opendkim" sh -c "test -s \"\$OPENDKIM_KEY_DIR/send.$NAME/r1.private\"" \
  && pass "restore: database and DKIM key back to the backup; services healthy" || fail "restore"

echo "== U upgrade and D-35"
ids() { for s in postgres opendkim postfix symfony-app webhook-worker nginx validator delivery; do podman inspect "$I-$s" --format '{{.Id}}'; done | sort | tr '\n' ' '; }
before="$(ids)"
prod stop >/dev/null && [[ "$(prod machine systemctl is-active "$I-ingress.socket" 2>/dev/null)" != active ]] && stopped=yes || stopped=no
prod start >/dev/null 2>&1 && [[ "$(ids)" == "$before" && $stopped == yes ]] \
  && [[ "$(prod machine systemctl is-active "$I-ingress.service")" == active ]] \
  && pass "stop/start keep every container (D-35); stop releases the ingress socket, start brings nginx back through it" || fail "stop/start (socket released: $stopped)"
prod upgrade rehearsal2 --no-backup >/dev/null 2>&1
[[ "$(podman inspect "$I-symfony-app" --format '{{.ImageName}}')" == "localhost/smarthost-app:rehearsal2" && "$(ids)" != "$before" \
   && "$(sql "SELECT count(*) FROM clients")" == "$clients_before" ]] \
  && pass "upgrade: new image tag, containers recreated, data kept, migrations and grants re-run" || fail "upgrade"
out="$(prod ingress-check 2>&1)"
[[ "$(grep -c '^FAIL' <<<"$out")" == 0 ]] && pass "after the upgrade the recreated nginx again preserves client addresses" || { echo "$out" | grep FAIL; fail "ingress after upgrade"; }

echo "== Z development pod untouched"
[[ "$(dev_snapshot)" == "$DEV_BEFORE" ]] && pass "no development container was created, changed or stopped" || fail "development pod changed"
echo "phase8-rehearsal: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
