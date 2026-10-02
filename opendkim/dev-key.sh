#!/bin/sh
# Generate a DISPOSABLE development DKIM key pair inside the OpenDKIM key volume
# and register it in the KeyTable/SigningTable. Never use for production.
# Usage: smarthost-dkim-dev-key <domain> <selector>
# Prints only the public DNS TXT record; the private key never leaves the volume.
set -eu
domain="${1:?usage: smarthost-dkim-dev-key <domain> <selector>}"
selector="${2:?usage: smarthost-dkim-dev-key <domain> <selector>}"
: "${OPENDKIM_KEY_DIR:?}" "${OPENDKIM_TABLES_DIR:?}"
case "${SMARTHOST_ENV:-}" in development|test) ;; *) echo "refusing: dev keys only in development/test" >&2; exit 64 ;; esac
dir="$OPENDKIM_KEY_DIR/$domain"
mkdir -p "$dir" "$OPENDKIM_TABLES_DIR"
if [ ! -f "$dir/$selector.private" ]; then
    opendkim-genkey -b 2048 -h rsa-sha256 -r -d "$domain" -s "$selector" -D "$dir"
fi
chown -R opendkim:opendkim "$OPENDKIM_KEY_DIR"; chmod 0700 "$dir"; chmod 0600 "$dir/$selector.private"
entry="$selector._domainkey.$domain"
touch "$OPENDKIM_TABLES_DIR/KeyTable" "$OPENDKIM_TABLES_DIR/SigningTable"
grep -q "^$entry " "$OPENDKIM_TABLES_DIR/KeyTable" || \
    echo "$entry $domain:$selector:$dir/$selector.private" >> "$OPENDKIM_TABLES_DIR/KeyTable"
grep -q " $entry\$" "$OPENDKIM_TABLES_DIR/SigningTable" || \
    echo "*@$domain $entry" >> "$OPENDKIM_TABLES_DIR/SigningTable"
echo "DEV-ONLY DKIM key registered for $domain selector $selector. Public record:"
cat "$dir/$selector.txt"
