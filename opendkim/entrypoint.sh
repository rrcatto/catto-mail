#!/bin/sh
# Smarthost OpenDKIM entrypoint.
#   * Listens on the port of SMARTHOST_OPENDKIM_MILTER_ADDRESS (inet:host:port).
#   * Signs only messages Postfix tags with milter_macro_daemon_name=ORIGINATING
#     (set solely on the authenticated submission service), never inbound DSNs.
#   * Signs only domains present in the KeyTable/SigningTable in OPENDKIM_TABLES_DIR.
#   * OpenDKIM logs to syslog; a foreground busybox syslogd forwards it to stdout
#     so it reaches journald via Podman.
set -eu
die() { echo "smarthost-opendkim: FATAL: $*" >&2; exit 64; }
: "${SMARTHOST_OPENDKIM_MILTER_ADDRESS:?}" "${OPENDKIM_KEY_DIR:?}" "${OPENDKIM_TABLES_DIR:?}"
port="${SMARTHOST_OPENDKIM_MILTER_ADDRESS##*:}"
case "$port" in ''|*[!0-9]*) die "cannot derive port from $SMARTHOST_OPENDKIM_MILTER_ADDRESS" ;; esac

mkdir -p "$OPENDKIM_TABLES_DIR" /run/opendkim
touch "$OPENDKIM_TABLES_DIR/KeyTable" "$OPENDKIM_TABLES_DIR/SigningTable"
chown -R opendkim:opendkim /run/opendkim
# Keys must be private to the opendkim user (RequireSafeKeys).
if [ -d "$OPENDKIM_KEY_DIR" ]; then
    chown -R opendkim:opendkim "$OPENDKIM_KEY_DIR"
    find "$OPENDKIM_KEY_DIR" -type d -exec chmod 0700 {} +
    find "$OPENDKIM_KEY_DIR" -type f -exec chmod 0600 {} +
fi

cat > /etc/opendkim.conf <<CONF
Syslog                  yes
SyslogSuccess           yes
LogWhy                  yes
UMask                   007
Mode                    s
Socket                  inet:${port}@0.0.0.0
UserID                  opendkim:opendkim
PidFile                 /run/opendkim/opendkim.pid
KeyTable                file:${OPENDKIM_TABLES_DIR}/KeyTable
SigningTable            refile:${OPENDKIM_TABLES_DIR}/SigningTable
MTA                     ORIGINATING
Canonicalization        relaxed/simple
OversignHeaders         From
RequireSafeKeys         true
CONF

if [ ! -s "$OPENDKIM_TABLES_DIR/SigningTable" ]; then
    echo "smarthost-opendkim: WARNING: SigningTable is empty; no domain will be signed" >&2
fi
busybox syslogd -n -O /dev/stdout &
exec opendkim -f -x /etc/opendkim.conf
