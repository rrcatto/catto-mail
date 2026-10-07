#!/usr/bin/env bash
# Installer test (specification 2.11) behind `smarthostctl test installer [--keep] [--with-upgrade]`.
#
# Runs install-catto-mail as root in a stand-in for a clean Ubuntu Server 26.04 LTS VPS: a
# systemd container (infra/tests/installer/Containerfile) with its own rootless Podman inside.
# The release is this working tree, committed as a test tag into a throwaway Git repository
# (infra/.generated/installer-test/release-repo), so the installer clones a tag exactly as it
# would from GitHub. It installs with --no-egress (no container has an Internet route; the
# production rules then accept the reserved .test names used here), so nothing resolves and no
# mail can leave, and live delivery stays HELD. The installer refuses private and documentation
# addresses as the public IPv4, so the test gives 1.2.3.4: nothing connects to it, and only the
# host agent's reverse-DNS check looks it up.
#
#   I  the installation: 21 steps, no FAIL, HELD, a sign-in link that is not in the log
#   R  a second run: finished steps are detected and skipped (idempotent)
#   S  the service user's units, the sign-in link redeemed through the ingress socket on 443,
#      the host agent's checks (the boot target, the ingress, client addresses), and the web
#      emergency stop (the delivery daemon pauses, the agent holds Postfix, typed resume)
#   B  a container restart standing in for a reboot: everything back, still HELD
#   U  (--with-upgrade) `prod upgrade` to a second tag, then `prod rollback` to its backup
#
# The image builds inside the VPS take most of the time (about half an hour on a laptop).
# The VPS container is removed at the end unless --keep is given. The development pod is
# never touched.
set -euo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GEN="$REPO/infra/.generated/installer-test"
RELEASE="$GEN/release-repo"
VPS=smarthost-installer-test
IMAGE=localhost/smarthost-installer-vps:26.04
NAME=catto-installer.test
IP=1.2.3.4
KEEP=false UPGRADE=false
for a in "$@"; do
  case "$a" in
    --keep) KEEP=true ;;
    --with-upgrade) UPGRADE=true ;;
    *) echo "usage: installer-test.sh [--keep] [--with-upgrade]" >&2; exit 64 ;;
  esac
done
PASS=0 FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS  $*"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL  $*"; }
as_svc() { podman exec -i "$VPS" su - cattomail -c "export XDG_RUNTIME_DIR=/run/user/\$(id -u); $1"; }
sql() { as_svc "podman exec -i smarthost-postgres sh -c 'psql -X -At -U \$POSTGRES_USER -d \$POSTGRES_DB'" <<<"$1"; }
waitsql() { local _; for _ in $(seq 1 "${3:-60}"); do [[ "$(sql "$1" 2>/dev/null)" == "$2" ]] && return 0; sleep 5; done; return 1; }
dev_snapshot() { podman ps -a --filter label=project=smarthost --format '{{.ID}} {{.Names}} {{.State}}' | grep -v " $VPS " | sort; }

# snapshot <tag>: the working tree (tracked and new files, not ignored ones) as a release tag.
snapshot() {
  [[ -d "$RELEASE/.git" ]] || git init -q "$RELEASE"
  (cd "$REPO" && git ls-files -co --exclude-standard -z | while IFS= read -r -d '' f; do [[ -f "$f" ]] && printf '%s\0' "$f"; done \
    | tar --null -T - -cf -) | tar -C "$RELEASE" -xf -
  git -C "$RELEASE" add -A
  git -C "$RELEASE" -c user.name=installer-test -c user.email=installer-test@example.invalid commit -q --allow-empty -m "installer test $1"
  git -C "$RELEASE" tag -f "$1" >/dev/null
}
install() {
  podman exec "$VPS" bash /src/catto-mail.git/install-catto-mail --non-interactive --repo file:///src/catto-mail.git --version v0.0.1 \
    --web-host "mail.$NAME" --mta-host "mta.$NAME" --bounce-domain "bounce.$NAME" --admin-email "admin@$NAME" \
    --mail-from "no-reply@$NAME" --public-ip "$IP" --no-egress
}
# https_get <path> [cookie]: "<status> <location>" from inside the VPS, through the ingress socket on 443.
https_get() {
  podman exec -i "$VPS" python3 - "$1" "mail.$NAME" "${2:-}" <<'PY'
import http.client, ssl, sys
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
c = http.client.HTTPSConnection("127.0.0.1", 443, context=ctx, timeout=60)
c.request("GET", sys.argv[1], headers={"Host": sys.argv[2]})
r = c.getresponse()
print(r.status, r.getheader("Location") or "")
PY
}
cleanup() {
  [[ $KEEP == true ]] && { echo "installer-test: kept $VPS (podman rm -f $VPS when done)"; return; }
  podman rm -f --time 30 "$VPS" >/dev/null 2>&1 || true
  rm -rf "$GEN"
}

DEV_BEFORE="$(dev_snapshot)"
podman rm -f --time 30 "$VPS" >/dev/null 2>&1 || true
rm -rf "$GEN"; mkdir -p "$GEN"
trap cleanup EXIT
podman build -q --label project=smarthost -t "$IMAGE" "$REPO/infra/tests/installer" >/dev/null
snapshot v0.0.1
# Rootless Podman inside a container needs /dev/fuse, /dev/net/tun and an unconfined profile;
# the low-port sysctl starts at the Linux default so the installer's own setting is tested.
podman run -d --name "$VPS" --label project=smarthost --hostname vps-test --systemd=always \
  --device /dev/fuse --device /dev/net/tun --security-opt label=disable --security-opt unmask=ALL \
  --security-opt seccomp=unconfined --cap-add SYS_ADMIN,NET_ADMIN --sysctl net.ipv4.ip_unprivileged_port_start=1024 \
  -v "$RELEASE:/src/catto-mail.git:ro" "$IMAGE" >/dev/null
podman exec "$VPS" systemctl is-system-running --wait >/dev/null 2>&1 || true

echo "== I installation"
if install > "$GEN/install-1.log" 2>&1; then pass "the installer finished (exit 0)"; else fail "installer exit: $(tail -n 15 "$GEN/install-1.log")"; fi
steps="$(grep -cE '^\[[0-9]{2}/21\] .* (PASS|WARN)' "$GEN/install-1.log" || true)"
[[ "$steps" == 21 ]] && pass "21 numbered steps, each PASS or WARN" || fail "steps: $(grep -E '^\[[0-9]{2}/21\]' "$GEN/install-1.log" | grep -v PASS)"
grep -q "^Live bulk delivery: HELD" "$GEN/install-1.log" && [[ "$(as_svc 'grep ^SMARTHOST_LIVE_DELIVERY_ENABLED= ~/catto-mail/infra/.env')" == *=false ]] \
  && pass "installed in HELD mode (live delivery off)" || fail "not HELD"
token="$(sed -n 's#^  https://mail\.catto-installer\.test/dashboard/login/verify?token=\([A-Za-z0-9_-]\{43\}\)$#\1#p' "$GEN/install-1.log")"
[[ -n "$token" ]] && ! podman exec "$VPS" grep -qF "$token" /var/log/catto-mail-install.log \
  && pass "a sign-in link is printed, and it is not in the installation log" || fail "sign-in link"
[[ "$(podman exec "$VPS" stat -c %a /home/cattomail/catto-mail/infra/.env)" == 600 ]] && pass "infra/.env is private (0600)" || fail "env mode"
grep -q "WARN  generated /home/cattomail/catto-mail-firewall.nft, not applied" "$GEN/install-1.log" \
  && pass "the firewall rules are generated, not applied" || fail "firewall step"
if [[ $FAIL -gt 0 ]]; then echo "installer-test: the installation failed, nothing further to test: $PASS passed, $FAIL failed"; exit 1; fi

echo "== R second run"
if install > "$GEN/install-2.log" 2>&1; then pass "a second run finishes (exit 0)"; else fail "second run: $(tail -n 15 "$GEN/install-2.log")"; fi
grep -q "kept the existing configuration" "$GEN/install-2.log" && grep -q "already built" "$GEN/install-2.log" \
  && grep -q "kept the installed certificates" "$GEN/install-2.log" \
  && pass "finished steps are detected: configuration, images and certificates kept" || fail "idempotence"

echo "== S services, sign-in, host agent"
states="$(as_svc 'systemctl --user is-active smarthost.service smarthost-ingress.socket smarthost-host-agent.service smarthost-backup.timer smarthost-tls-renew.timer smarthost-reputation-evaluate.timer' | tr '\n' ' ')"
[[ "$states" == "active active active active active active " ]] && pass "boot service, ingress socket, host agent and timers active" || fail "units: $states"
link="$(as_svc 'cd ~/catto-mail && infra/bin/smarthostctl prod admin-link' | sed -n 's#^sign_in_url: https://mail\.catto-installer\.test##p')"
[[ "$(https_get "$link")" == "302 /dashboard/operator/setup" ]] && pass "the sign-in link works through port 443 and opens System setup" || fail "sign-in: $(https_get "$link")"
waitsql "SELECT string_agg(result, ',' ORDER BY check_key) FROM system_checks WHERE check_key IN ('boot.smarthost-service', 'ingress.socket-binds', 'ingress.nginx-https-symfony-remote-addr-sees-each-client-s-own-address', 'host.operating-system', 'host.rootless')" \
  "pass,pass,pass,pass,pass" 60 \
  && pass "host agent: Ubuntu 26.04, rootless, boot target, ingress binds and client addresses" \
  || fail "agent checks: $(sql "SELECT check_key || '=' || result FROM system_checks WHERE result = 'fail'" | tr '\n' ' ')"

link="$(as_svc 'cd ~/catto-mail && infra/bin/smarthostctl prod admin-link' | sed -n 's#^sign_in_url: https://mail\.catto-installer\.test##p')"
out="$(podman exec -i "$VPS" python3 - "$link" "mail.$NAME" <<'PY'
# The web emergency stop as an administrator presses it: the database stop, the delivery
# daemon's pause, the host agent's Postfix hold; resuming needs the typed phrase.
import http.client, re, ssl, subprocess, sys, time, urllib.parse
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
cookie = ""
def req(path, form=None):
    global cookie
    c = http.client.HTTPSConnection("127.0.0.1", 443, context=ctx, timeout=60)
    h = {"Host": sys.argv[2], **({"Cookie": cookie} if cookie else {})}
    if form is not None:
        h["Content-Type"] = "application/x-www-form-urlencoded"
    c.request("POST" if form is not None else "GET", path, body=urllib.parse.urlencode(form) if form else None, headers=h)
    r = c.getresponse(); text = r.read().decode(errors="replace")
    cookie = (r.getheader("Set-Cookie") or cookie).split(";")[0]
    return text
def svc(cmd):
    return subprocess.run(["su", "-", "cattomail", "-c", cmd], capture_output=True, text=True).stdout.strip()
def sql(q):
    return subprocess.run(["su", "-", "cattomail", "-c", "podman exec -i smarthost-postgres sh -c 'psql -X -At -U $POSTGRES_USER -d $POSTGRES_DB'"],
                          input=q, capture_output=True, text=True).stdout.strip()
def until(fn, want, n=90):
    for _ in range(n):
        if fn() == want:
            return True
        time.sleep(1)
    return False
defer = lambda: svc("podman exec smarthost-postfix postconf -h defer_transports")
paused = lambda: sql("SELECT outbound_paused FROM delivery_heartbeats WHERE stopped_at IS NULL ORDER BY last_seen_at DESC LIMIT 1")
token = lambda page: re.search(r'name="_token" value="([^"]+)"', page).group(1)
req(sys.argv[1])
req("/dashboard/operator/system/stop", {"_token": token(req("/dashboard/operator/system")), "note": "installer test", "return_to": ""})
stop = sql("SELECT emergency_stop FROM delivery_controls WHERE id = 1") == "t" and until(paused, "t") and until(defer, "smtp")
shown = "PAUSED" in req("/dashboard/operator/system")
req("/dashboard/operator/system/resume", {"_token": token(req("/dashboard/operator/system")), "note": "test", "confirm": "yes"})
refused = sql("SELECT emergency_stop FROM delivery_controls WHERE id = 1") == "t"
req("/dashboard/operator/system/resume", {"_token": token(req("/dashboard/operator/system")), "note": "test over", "confirm": "RESUME SENDING"})
resumed = sql("SELECT emergency_stop FROM delivery_controls WHERE id = 1") == "f" and until(defer, "") and until(paused, "f")
print(f"stop={stop} shown={shown} refused={refused} resumed={resumed}")
PY
)"
[[ "$out" == "stop=True shown=True refused=True resumed=True" ]] \
  && pass "web emergency stop: the delivery daemon pauses, the agent holds Postfix; resume needs RESUME SENDING" || fail "emergency stop: $out"

echo "== B reboot (container restart)"
podman restart --time 60 "$VPS" >/dev/null
for _ in $(seq 1 60); do [[ "$(as_svc 'systemctl --user is-active smarthost.service' 2>/dev/null)" == active ]] && break; sleep 5; done
healthy="$(as_svc 'podman ps --filter health=healthy --format "{{.Names}}"' | wc -l)"
[[ "$healthy" == 8 && "$(as_svc 'systemctl --user is-active smarthost-ingress.socket')" == active ]] \
  && pass "after the restart all 8 containers are healthy behind the ingress socket" || fail "after restart: $healthy healthy"
[[ "$(https_get /healthz | cut -d' ' -f1)" == 200 && "$(as_svc 'grep ^SMARTHOST_LIVE_DELIVERY_ENABLED= ~/catto-mail/infra/.env')" == *=false ]] \
  && pass "the web application answers on 443; still HELD" || fail "after restart: $(https_get /healthz)"

if [[ $UPGRADE == true ]]; then
  echo "== U upgrade and rollback"
  as_svc 'cd ~/catto-mail && infra/bin/smarthostctl prod console smarthost:client:create --company "Before upgrade" --contact-email ops@example.org' >/dev/null
  snapshot v0.0.2
  out="$(as_svc 'cd ~/catto-mail && git fetch -q --tags origin && git checkout -q v0.0.2 && infra/bin/smarthostctl prod upgrade 0.0.2' 2>&1)" || true
  backup="$(sed -n 's/^smarthostctl prod: backup written to \([^ ]*\) .*/\1/p' <<<"$out")"
  [[ "$out" == *"upgraded 0.0.1 -> 0.0.2"* && -n "$backup" ]] && pass "prod upgrade: backup, new images, migrations, grants, runtime preflight" || fail "upgrade: ${out: -400}"
  as_svc 'cd ~/catto-mail && infra/bin/smarthostctl prod console smarthost:client:create --company "After upgrade" --contact-email ops@example.org' >/dev/null
  out="$(as_svc "cd ~/catto-mail && git checkout -q v0.0.1 && infra/bin/smarthostctl prod rollback 0.0.1 $backup" 2>&1)" || true
  [[ "$out" == *"rolled back to 0.0.1"* && "$(sql "SELECT count(*) FROM clients WHERE company_name = 'After upgrade'")" == 0 \
     && "$(sql "SELECT count(*) FROM clients WHERE company_name = 'Before upgrade'")" == 1 ]] \
    && pass "prod rollback: previous images and the database of the pre-upgrade backup" || fail "rollback: ${out: -400}"
fi

echo "== Z development pod untouched"
[[ "$(dev_snapshot)" == "$DEV_BEFORE" ]] && pass "no development container was created, changed or stopped" || fail "development pod changed"
echo "installer-test: $PASS passed, $FAIL failed"
[[ $FAIL == 0 ]]
