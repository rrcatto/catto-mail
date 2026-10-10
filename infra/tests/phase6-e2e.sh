#!/usr/bin/env bash
# Smarthost Phase 6 end-to-end test against the running `smarthost` pod, through
# nginx -> PHP-FPM exactly as a mail client or browser would (infra/tests/phase6_e2e.py):
#
#   T  a tracked subscription job is delivered through Go -> Postfix -> OpenDKIM ->
#      Mailpit; the pixel and the rewritten link from the delivered HTML record
#      events and redirect only to the stored target; open-redirect attempts fail;
#      the unsubscribe link is not tracked
#   D  dashboards: passwordless sign-in (link emailed through Postfix to Mailpit,
#      DKIM-signed, single use; APP_ADMIN_EMAIL gets ADMIN), client pages, timeline,
#      CSV export, tenant isolation, operator-only area and ACL, unmatched-DSN
#      dismissal, operator block and lift, audit log, POST-only sign-out
#   L  logs: neither nginx nor the application logged the tracking token
#
# All traffic stays on the internal network. Usage: infra/tests/phase6-e2e.sh
set -uo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GEN="$REPO/infra/.generated"
TOOLS=localhost/smarthost-testtools:dev
ev() { python3 "$REPO/infra/lib/smarthost_render.py" get "$1" --env "$REPO/infra/.env"; }  # .env value, else built-in

podman pod exists smarthost && [[ "$(podman pod inspect smarthost --format '{{.State}}')" == Running ]] \
  || { echo "phase6-e2e: the smarthost pod is not running (smarthostctl start)" >&2; exit 2; }
"$GEN/podman/smarthost-pod.sh" wait-healthy 300 >/dev/null || { echo "phase6-e2e: services not healthy" >&2; exit 2; }
podman build -q -t "$TOOLS" "$REPO/infra/tests" >/dev/null

console() { podman exec -i -u www-data smarthost-symfony-app php bin/console "$@"; }
field() { sed -n "s/^ *$1: //p" | head -n1; }
T="$(date +%s)"
OPERATOR="p6-op-$T@smarthost-dev.test"; USER_A="p6-a-$T@smarthost-dev.test"; USER_B="p6-b-$T@smarthost-dev.test"

boot="$(console smarthost:dev:bootstrap 2>/dev/null)"
KEY_A="$(field api_key <<<"$boot")"; CLIENT_A="$(field client_id <<<"$boot")"
[[ -n "$KEY_A" ]] || { echo "phase6-e2e: could not obtain a development API key" >&2; exit 2; }
CLIENT_B="$(console smarthost:client:create --company "Phase6 E2E B $T" --contact-email b@smarthost-dev.test --status active | field client_id)"
console smarthost:user:create "$OPERATOR" --role OPERATOR >/dev/null
console smarthost:user:create "$USER_A" >/dev/null
console smarthost:user:create "$USER_B" >/dev/null
console smarthost:membership:set "$USER_A" "$CLIENT_A" member >/dev/null
console smarthost:membership:set "$USER_B" "$CLIENT_B" admin >/dev/null
since="$(date +%Y-%m-%dT%H:%M:%S%:z)"

out="$(podman run --rm --network smarthost-internal --label project=smarthost \
  -e SMARTHOST_DB_NAME="$(ev SMARTHOST_DB_NAME)" -e SMARTHOST_DB_OWNER_USER="$(ev SMARTHOST_DB_OWNER_USER)" \
  -e SMARTHOST_DB_OWNER_PASSWORD="$(ev SMARTHOST_DB_OWNER_PASSWORD)" -e E2E_KEY_A="$KEY_A" -e E2E_CLIENT_A="$CLIENT_A" \
  -e E2E_CLIENT_B="$CLIENT_B" -e E2E_USER_A="$USER_A" -e E2E_USER_B="$USER_B" -e E2E_OPERATOR="$OPERATOR" -e E2E_ADMIN="$(ev APP_ADMIN_EMAIL)" \
  --entrypoint python "$TOOLS" /opt/tests/phase6_e2e.py)"
status=$?
printf '%s\n' "$out" | sed '$d'
summary="$(printf '%s\n' "$out" | tail -n1)"
PASS="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["pass"])' "$summary" 2>/dev/null || echo 0)"
FAIL="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["fail"])' "$summary" 2>/dev/null || echo 1)"
TOKEN="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["token"])' "$summary" 2>/dev/null || true)"

echo "== L tracking tokens are not logged"
nginx_log="$(podman logs --since "$since" smarthost-nginx 2>&1)"
app_log="$(podman logs --since "$since" smarthost-symfony-app 2>&1)"
if [[ -n "$TOKEN" ]] && ! grep -qF "$TOKEN" <<<"$nginx_log" && grep -qF '/t/o/[token].gif' <<<"$nginx_log" && grep -qF '/t/c/[token]/1' <<<"$nginx_log"; then
  PASS=$((PASS+1)); echo "  PASS  nginx access log shows tracking requests with the token redacted"
else FAIL=$((FAIL+1)); echo "  FAIL  nginx access log (token present or requests missing)"; fi
if [[ -n "$TOKEN" ]] && ! grep -qF "$TOKEN" <<<"$app_log"; then
  PASS=$((PASS+1)); echo "  PASS  application log does not contain the token"
else FAIL=$((FAIL+1)); echo "  FAIL  application log contains the token"; fi

echo "phase6-e2e: $PASS passed, $FAIL failed"
[[ "$status" -eq 0 && "$FAIL" -eq 0 ]]
