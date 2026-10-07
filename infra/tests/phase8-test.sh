#!/usr/bin/env bash
# catto-mail Phase 8 production-tooling tests (`smarthostctl test phase8`).
# Throwaway containers only (named smarthost-phase8-*, label project=smarthost), every
# one with --network none: nothing here touches the running development pod or the Internet.
#
#   U  unit tests (infra/tests/phase8): production configuration rules, template and
#      init-env, the rendered production topology (networks, no published ports, the
#      ingress units, keys), DNS wire client, SPF/DKIM/DMARC/TLS logic, preflight checks
#      with fixtures (host prerequisites, the ingress proof with preserving and collapsing
#      fakes)
#   S  shellcheck of the rendered production topology and the prod tooling
#   P  Postfix: production held mode (only APP_MAIL_FROM delivered), live mode,
#      development capture unchanged, relayhost refused in production, the
#      emergency pause flag (at start and at runtime), submission sender ownership,
#      the production port-25 listener (PROXY protocol on postfix-ingress only; the
#      address in the PROXY header is the client Postfix logs)
#   N  nginx: the production templates (HTTPS listen = PROXY_HTTPS_BIND, SMTP stream
#      server on POSTFIX_SMTP_BIND with the PROXY protocol); development unchanged
#   K  production DKIM key tool: generate, activate, rotate, retire, dns, pubkey
set -uo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TAG=phase8-test
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS  $*"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL  $*"; }
mkdir -p "$REPO/infra/.generated"; TMP="$(mktemp -d "$REPO/infra/.generated/phase8-test.XXXXXX")"; trap 'rm -rf "$TMP"; podman rm -f smarthost-phase8-postfix >/dev/null 2>&1 || true' EXIT

echo "== U unit tests"
if out="$(podman run --rm --name smarthost-phase8-unit --network none --label project=smarthost -v "$REPO:/repo:ro,Z" -w /repo/infra/tests/phase8 \
    docker.io/library/python:3.14-slim-trixie python -m unittest -v 2>&1)"; then
  pass "$(grep -E '^Ran [0-9]+ tests' <<<"$out")"
else
  echo "$out" | tail -40; fail "unit tests"
fi

echo "== S shellcheck"
python3 - "$REPO" "$TMP" <<'PY'
import sys
sys.path.insert(0, sys.argv[1] + "/infra/tests/phase8")
from pathlib import Path
from fixtures import production_values, render
env = Path(sys.argv[2]) / "prod.env"
env.write_text("\n".join(f"{k}={v}" for k, v in production_values().items()) + "\n")
render.render(env, Path(sys.argv[2]) / "generated")
PY
if podman run --rm --name smarthost-phase8-shellcheck --label project=smarthost --network none -v "$REPO:/repo:ro,Z" -v "$TMP:/t:ro,Z" -w /repo docker.io/koalaman/shellcheck:stable -S warning \
    /t/generated/podman/smarthost-production.sh infra/bin/smarthostctl infra/bin/smarthostctl-prod infra/tests/phase8-test.sh \
    infra/tests/phase8-rehearsal.sh; then
  pass "rendered production topology and prod tooling are shellcheck-clean"
else
  fail "shellcheck"
fi
if podman run --rm --name smarthost-phase8-shellcheck-sh --label project=smarthost --network none -v "$REPO:/repo:ro,Z" -w /repo docker.io/koalaman/shellcheck:stable -s sh -S warning \
    postfix/entrypoint.sh postfix/control.sh opendkim/dkim-key.sh; then
  pass "Postfix entrypoint/control and the DKIM key tool are shellcheck-clean"
else
  fail "shellcheck (sh)"
fi

echo "== P Postfix modes"
podman build -q -t "localhost/smarthost-postfix:$TAG" "$REPO/postfix" >/dev/null
podman build -q -t "localhost/smarthost-opendkim:$TAG" "$REPO/opendkim" >/dev/null
envfile() {  # postfix.env of the fixture production configuration, with overrides
  grep -v -E '^(#|POSTFIX_TLS_|SMARTHOST_LIVE_DELIVERY_ENABLED|POSTFIX_RELAYHOST|SMARTHOST_ENV)' "$TMP/generated/env/postfix.env"
  echo "POSTFIX_TLS_CERT_FILE=/tmp/tls/c"; echo "POSTFIX_TLS_KEY_FILE=/tmp/tls/k"
  echo "SMARTHOST_ENV=$1"; echo "SMARTHOST_LIVE_DELIVERY_ENABLED=$2"; echo "POSTFIX_RELAYHOST=$3"
}
start_postfix() {  # env live relay [pre-command]
  envfile "$1" "$2" "$3" > "$TMP/pf.env"
  podman rm -f smarthost-phase8-postfix >/dev/null 2>&1 || true
  podman run -d --name smarthost-phase8-postfix --label project=smarthost --network none --add-host postfix-ingress:127.0.0.2 --env-file "$TMP/pf.env" \
    --entrypoint sh "localhost/smarthost-postfix:$TAG" -c "mkdir -p /tmp/tls && openssl req -x509 -newkey rsa:2048 -nodes -days 2 \
      -subj /CN=x -keyout /tmp/tls/k -out /tmp/tls/c 2>/dev/null && ${4:-true} && exec smarthost-postfix-entrypoint" >/dev/null
  for _ in $(seq 1 30); do podman exec smarthost-phase8-postfix postfix status >/dev/null 2>&1 && return 0; sleep 1; done
  podman logs smarthost-phase8-postfix | tail -5; return 1
}
pc() { podman exec smarthost-phase8-postfix postconf -h "$@"; }
appfrom="$(grep '^APP_MAIL_FROM=' "$TMP/generated/env/postfix.env" | cut -d= -f2)"
bounce="$(grep '^SMARTHOST_BOUNCE_DOMAIN=' "$TMP/generated/env/postfix.env" | cut -d= -f2)"

start_postfix production false "" && {
  [[ "$(pc default_transport)" == "retry:live delivery is not activated" && -z "$(pc relayhost)" ]] \
    && pass "production held mode: no relayhost, default transport retry(8) (outbound refused with 450)" || fail "held mode: $(pc default_transport relayhost)"
  [[ "$(pc sender_dependent_default_transport_maps)" == "inline:{ $appfrom=smtp: }" ]] \
    && pass "held mode delivers only the sign-in sender ($appfrom)" || fail "held map: $(pc sender_dependent_default_transport_maps)"
  m="$(podman exec smarthost-phase8-postfix postmap -q "bounce+tok3n@$bounce" regexp:/etc/postfix/smarthost_sender_logins)"
  a="$(podman exec smarthost-phase8-postfix postmap -q "$appfrom" regexp:/etc/postfix/smarthost_sender_logins)"
  o="$(podman exec smarthost-phase8-postfix postmap -q "someone@client.example" regexp:/etc/postfix/smarthost_sender_logins || true)"
  [[ "$m" == smarthost-delivery@* && "$a" == smarthost-app@* && -z "$o" ]] \
    && pass "submission sender ownership: VERP -> delivery account, sign-in sender -> app account, others -> nobody" \
    || fail "sender logins: '$m' '$a' '$o'"
  [[ "$(podman exec smarthost-phase8-postfix postconf -P submission/inet/smtpd_sender_restrictions)" == *reject_sender_login_mismatch* ]] \
    && pass "submission rejects sender/login mismatches" || fail "submission sender restrictions"
  [[ "$(pc disable_vrfy_command smtpd_helo_required | tr '\n' ' ')" == "yes yes " ]] && pass "port 25 hardening (VRFY off, HELO required)" || fail "hardening"
  podman exec smarthost-phase8-postfix smarthost-postfix-control pause "phase8 test" >/dev/null
  [[ "$(pc defer_transports)" == smtp ]] && podman exec smarthost-phase8-postfix test -e /var/lib/smarthost/postfix-observability/control/outbound-paused \
    && pass "runtime pause: defer_transports=smtp and the durable flag" || fail "pause"
  [[ "$(podman exec smarthost-phase8-postfix smarthost-postfix-control status | head -1)" == "outbound: PAUSED since"*"phase8 test" ]] \
    && pass "pause status reports the reason" || fail "pause status"
  podman exec smarthost-phase8-postfix smarthost-postfix-control resume >/dev/null
  [[ -z "$(pc defer_transports)" ]] && ! podman exec smarthost-phase8-postfix test -e /var/lib/smarthost/postfix-observability/control/outbound-paused \
    && pass "resume clears the pause" || fail "resume"
} || fail "Postfix did not start in production held mode"

# Production port 25: only the PROXY-protocol listener on postfix-ingress (nginx's
# path) plus a container-local listener for the health check.
start_postfix production false "" && {
  m="$(podman exec smarthost-phase8-postfix postconf -M)"
  grep -q '^postfix-ingress:smtp  *inet .*smtpd_upstream_proxy_protocol=haproxy' <<<"$m" && grep -q '^127.0.0.1:smtp  *inet ' <<<"$m" \
    && ! grep -qE '^(smtp|25)  *inet ' <<<"$m" \
    && pass "production port 25: PROXY-protocol listener on postfix-ingress only (+ container-local health listener)" || fail "listeners: $m"
  podman exec smarthost-phase8-postfix sh -c "{ printf 'PROXY TCP4 198.51.100.7 127.0.0.2 40001 25\r\n'; sleep 1; printf 'EHLO probe.invalid\r\n'; sleep 1; printf 'QUIT\r\n'; } | nc -w 5 127.0.0.2 25" >/dev/null
  podman exec smarthost-phase8-postfix sh -c "{ sleep 1; printf 'EHLO direct.invalid\r\n'; sleep 7; } | nc -w 9 127.0.0.2 25" > "$TMP/noproxy.out" 2>&1
  sleep 2
  log="$(podman exec smarthost-phase8-postfix sh -c 'cat /var/lib/smarthost/postfix-observability/log/postfix.log')"
  grep -q 'connect from unknown\[198.51.100.7\]' <<<"$log" \
    && pass "the client address in the PROXY header is the SMTP peer Postfix logs (198.51.100.7)" || fail "PROXY peer: $(grep 'connect from' <<<"$log" | tail -3)"
  ! grep -q '^220 ' "$TMP/noproxy.out" \
    && pass "a connection without the PROXY header gets no SMTP session on the ingress listener" || fail "no-PROXY connection: $(cat "$TMP/noproxy.out")"
} || fail "Postfix did not start in production held mode (listeners)"

start_postfix production false "" "mkdir -p /var/lib/smarthost/postfix-observability/control && touch /var/lib/smarthost/postfix-observability/control/outbound-paused" \
  && [[ "$(pc defer_transports)" == smtp ]] && pass "a pause in force survives a restart (flag read at start)" || fail "pause at start"

start_postfix production true "" && [[ "$(pc default_transport)" == smtp && -z "$(pc sender_dependent_default_transport_maps)" ]] \
  && pass "production live mode: direct smtp delivery" || fail "live mode"

start_postfix development false "[mailpit]:1025" && [[ "$(pc relayhost)" == "[mailpit]:1025" && "$(pc default_transport)" == smtp ]] \
  && pass "development capture mode unchanged (relay to Mailpit)" || fail "capture mode"
m="$(podman exec smarthost-phase8-postfix postconf -M 2>/dev/null)"
grep -qE '^smtp  *inet ' <<<"$m" && ! grep -q 'postfix-ingress\|haproxy' <<<"$m" \
  && pass "development port 25 unchanged (plain listener, no PROXY protocol)" || fail "development listeners: $m"
envfile production false "" > "$TMP/pf.env"
out="$(timeout 60 podman run --rm --name smarthost-phase8-postfix-noingress --label project=smarthost --network none --env-file "$TMP/pf.env" --entrypoint sh "localhost/smarthost-postfix:$TAG" -c \
  "mkdir -p /tmp/tls && openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=x -keyout /tmp/tls/k -out /tmp/tls/c 2>/dev/null && smarthost-postfix-entrypoint" 2>&1)"
[[ "$out" == *"needs the ingress network address postfix-ingress"* ]] && pass "production Postfix refuses to start outside the ingress topology" || fail "no ingress: ${out: -200}"

envfile production false "[mailpit]:1025" > "$TMP/pf.env"
out="$(timeout 60 podman run --rm --name smarthost-phase8-postfix-refused --label project=smarthost --network none --add-host postfix-ingress:127.0.0.2 --env-file "$TMP/pf.env" --entrypoint sh "localhost/smarthost-postfix:$TAG" -c \
  "mkdir -p /tmp/tls && openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=x -keyout /tmp/tls/k -out /tmp/tls/c 2>/dev/null && smarthost-postfix-entrypoint" 2>&1)"
[[ "$out" == *"production must not set POSTFIX_RELAYHOST"* ]] && pass "production refuses a relayhost (no Mailpit in production)" || fail "relayhost: $out"
envfile development true "" > "$TMP/pf.env"
out="$(timeout 60 podman run --rm --name smarthost-phase8-postfix-live-dev --label project=smarthost --network none --env-file "$TMP/pf.env" --entrypoint smarthost-postfix-entrypoint "localhost/smarthost-postfix:$TAG" 2>&1)"
[[ "$out" == *"only permitted with SMARTHOST_ENV=production"* ]] && pass "live delivery outside production refused" || fail "live outside production: $out"
podman rm -f smarthost-phase8-postfix >/dev/null 2>&1

echo "== N nginx ingress templates"
podman build -q -t "localhost/smarthost-nginx:$TAG" "$REPO/infra/nginx" >/dev/null && pass "nginx image builds (production HTTPS template derived from the development one, listen line only)" \
  || fail "nginx build (the derivation check failed?)"
mkdir -p "$TMP/tls" && openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=x -keyout "$TMP/tls/k" -out "$TMP/tls/c" 2>/dev/null && chmod 644 "$TMP/tls/k"
nginx_conf() {  # template-dir [extra env...]: the rendered configuration (nginx -T)
  local dir="$1"; shift
  podman run --rm --name smarthost-phase8-nginx --label project=smarthost --network none --add-host postfix-ingress:127.0.0.2 \
    -v "$TMP/tls:/tls:ro,Z" --env-file "$TMP/generated/env/proxy.env" -e PROXY_TLS_CERT_FILE=/tls/c -e PROXY_TLS_KEY_FILE=/tls/k \
    -e NGINX_LOCAL_RESOLVERS=10.89.20.1 ${dir:+-e NGINX_ENVSUBST_TEMPLATE_DIR=$dir} "$@" --entrypoint sh "localhost/smarthost-nginx:$TAG" \
    -c '/docker-entrypoint.sh nginx -v >/dev/null 2>&1 && nginx -T 2>&1'
}
conf="$(nginx_conf /etc/nginx/templates-production -e PROXY_HTTPS_BIND=127.0.0.3:443 -e POSTFIX_SMTP_BIND=127.0.0.3:25)"
grep -q 'syntax is ok' <<<"$conf" && grep -q '^    listen 127.0.0.3:443 ssl;' <<<"$conf" && grep -q '^    listen 127.0.0.3:25;' <<<"$conf" \
  && grep -q '^    proxy_pass postfix-ingress:25;' <<<"$conf" && grep -q '^    proxy_protocol on;' <<<"$conf" \
  && pass "production nginx: HTTPS on PROXY_HTTPS_BIND, SMTP stream on POSTFIX_SMTP_BIND -> postfix-ingress:25 with the PROXY protocol (nginx -t ok)" \
  || fail "production nginx: $(grep -E 'listen|proxy_|emerg' <<<"$conf" | head -8)"
conf="$(nginx_conf "")"
grep -q 'syntax is ok' <<<"$conf" && grep -q '^    listen 443 ssl;' <<<"$conf" && ! grep -q 'stream {\|proxy_protocol' <<<"$conf" \
  && pass "development nginx unchanged: listen 443, no SMTP stream" || fail "development nginx: $(grep -E 'listen|stream' <<<"$conf" | head -5)"

echo "== K production DKIM keys"
out="$(podman run --rm --name smarthost-phase8-dkim --label project=smarthost --network none -e OPENDKIM_KEY_DIR=/k -e OPENDKIM_TABLES_DIR=/t --entrypoint sh "localhost/smarthost-opendkim:$TAG" -c '
set -e
smarthost-dkim-key generate send.example.org s1 >/dev/null
smarthost-dkim-key list | grep -q "^send.example.org	s1	no	present	2048$"
smarthost-dkim-key activate send.example.org s1 >/dev/null
smarthost-dkim-key generate send.example.org s2 >/dev/null
smarthost-dkim-key generate other.example.net k1 >/dev/null
! smarthost-dkim-key generate send.example.org s1 2>/dev/null
! smarthost-dkim-key retire send.example.org s1 2>/dev/null
smarthost-dkim-key activate send.example.org s2 >/dev/null
smarthost-dkim-key retire send.example.org s1 >/dev/null
test ! -e /k/send.example.org/s1.private
test "$(grep -c send.example.org /t/SigningTable)" = 1
grep -q "^\*@send.example.org s2._domainkey.send.example.org$" /t/SigningTable
p="$(smarthost-dkim-key pubkey send.example.org s2)"
smarthost-dkim-key dns send.example.org | grep -q "^value: v=DKIM1; k=rsa; p=$p$"
test "$(stat -c %a /k/send.example.org/s2.private)" = 600
! smarthost-dkim-key generate "Bad_Domain" x 2>/dev/null
echo ok' 2>&1)"
[[ "$out" == ok ]] && pass "generate/activate/rotate/retire/dns/pubkey; never overwrites; keys 0600; active selector protected" \
  || fail "DKIM key tool: $out"
podman rmi -f "localhost/smarthost-postfix:$TAG" "localhost/smarthost-opendkim:$TAG" "localhost/smarthost-nginx:$TAG" >/dev/null 2>&1 || true

echo "phase8-test: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
