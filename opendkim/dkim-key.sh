#!/bin/sh
# smarthost-dkim-key: production DKIM key management inside the OpenDKIM
# container (Phase 8, specification 2.9). OpenDKIM is the only service that holds
# DKIM private keys; they are generated here, in the OpenDKIM key volume, and
# never leave it. Only public material is printed.
#
#   generate <domain> <selector> [bits]   new RSA key (default 2048) in the key
#                                         volume and a KeyTable entry; NOT yet
#                                         used for signing (publish DNS first)
#   activate <domain> <selector>          sign <domain> with this selector (the
#                                         SigningTable entry); restart OpenDKIM after
#   retire <domain> <selector>            remove an inactive selector's key and
#                                         KeyTable entry (after its DNS record is gone)
#   dns <domain> [selector]               the TXT record to publish (default: the
#                                         active selector), from the private key
#   pubkey <domain> <selector>            only the p= value (preflight comparison)
#   list                                  domain, selector, active, key present, bits
#
# Planned rotation: generate a new selector, publish its TXT record, wait for
# DNS, activate it (and record it with `smarthost:domain:dkim`), keep the old
# record published for a few days, then retire the old selector.
set -eu
: "${OPENDKIM_KEY_DIR:?}" "${OPENDKIM_TABLES_DIR:?}"
kt="$OPENDKIM_TABLES_DIR/KeyTable"
st="$OPENDKIM_TABLES_DIR/SigningTable"
die() { echo "smarthost-dkim-key: $*" >&2; exit 64; }
mkdir -p "$OPENDKIM_TABLES_DIR"
touch "$kt" "$st"

valid_domain() { printf '%s' "$1" | grep -Eq '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$' || die "invalid domain '$1' (lower-case DNS name)"; }
valid_selector() { printf '%s' "$1" | grep -Eq '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$' || die "invalid selector '$1'"; }
keyfile() { echo "$OPENDKIM_KEY_DIR/$1/$2.private"; }
entry() { echo "$2._domainkey.$1"; }
re() { printf '%s' "$1" | sed 's/\./\\./g'; }
active_selector() { sed -n "s/^\*@$(re "$1") \([^ .]*\)\._domainkey\..*/\1/p" "$st" | head -n1; }
pubkey() { openssl pkey -in "$(keyfile "$1" "$2")" -pubout -outform DER 2>/dev/null | base64 | tr -d '\n'; }
bits() { openssl pkey -in "$(keyfile "$1" "$2")" -noout -text 2>/dev/null | sed -n 's/.*Private-Key: (\([0-9]*\) bit.*/\1/p' | head -n1; }
# Replace or remove lines without a temporary file outside the tables directory.
drop_lines() { pattern="$1"; file="$2"; grep -v -- "$pattern" "$file" > "$file.tmp" || true; cat "$file.tmp" > "$file"; rm -f "$file.tmp"; }

cmd="${1:-list}"; shift || true
case "$cmd" in
    generate)
        domain="${1:?usage: generate <domain> <selector> [bits]}"; selector="${2:?selector}"; nbits="${3:-2048}"
        valid_domain "$domain"; valid_selector "$selector"
        case "$nbits" in 2048|3072|4096) ;; *) die "bits must be 2048, 3072 or 4096" ;; esac
        [ ! -e "$(keyfile "$domain" "$selector")" ] || die "a key for $domain selector $selector already exists (keys are never overwritten)"
        dir="$OPENDKIM_KEY_DIR/$domain"
        mkdir -p "$dir"
        umask 077
        openssl genpkey -algorithm RSA -pkeyopt "rsa_keygen_bits:$nbits" -out "$(keyfile "$domain" "$selector")" 2>/dev/null
        chown -R opendkim:opendkim "$dir"; chmod 0700 "$dir"; chmod 0600 "$(keyfile "$domain" "$selector")"
        grep -q "^$(entry "$domain" "$selector") " "$kt" || \
            echo "$(entry "$domain" "$selector") $domain:$selector:$(keyfile "$domain" "$selector")" >> "$kt"
        echo "generated $nbits-bit key for $domain selector $selector (not yet signing; publish the record, then activate)"
        "$0" dns "$domain" "$selector"
        ;;
    activate)
        domain="${1:?usage: activate <domain> <selector>}"; selector="${2:?selector}"
        valid_domain "$domain"; valid_selector "$selector"
        [ -s "$(keyfile "$domain" "$selector")" ] || die "no key for $domain selector $selector (generate it first)"
        grep -q "^$(entry "$domain" "$selector") " "$kt" || die "selector $selector of $domain is not in the KeyTable"
        drop_lines "^\*@$(re "$domain") " "$st"
        echo "*@$domain $(entry "$domain" "$selector")" >> "$st"
        echo "SigningTable: $domain is signed with selector $selector (restart OpenDKIM to apply)"
        ;;
    retire)
        domain="${1:?usage: retire <domain> <selector>}"; selector="${2:?selector}"
        valid_domain "$domain"; valid_selector "$selector"
        [ "$(active_selector "$domain")" != "$selector" ] || die "$selector is the active selector of $domain; activate another one first"
        drop_lines "^$(re "$(entry "$domain" "$selector")") " "$kt"
        rm -f "$(keyfile "$domain" "$selector")"
        echo "retired $domain selector $selector (key deleted, KeyTable entry removed)"
        ;;
    dns|pubkey)
        domain="${1:?usage: $cmd <domain> [selector]}"; valid_domain "$domain"
        selector="${2:-$(active_selector "$domain")}"
        [ -n "$selector" ] || die "$domain has no active selector; name one"
        valid_selector "$selector"
        [ -s "$(keyfile "$domain" "$selector")" ] || die "no key for $domain selector $selector"
        p="$(pubkey "$domain" "$selector")"
        if [ "$cmd" = pubkey ]; then echo "$p"; exit 0; fi
        echo "name:  $(entry "$domain" "$selector")"
        echo "type:  TXT"
        echo "value: v=DKIM1; k=rsa; p=$p"
        # Zone-file form: one TXT string is at most 255 characters.
        printf 'zone:  %s. IN TXT ( "v=DKIM1; k=rsa; "' "$(entry "$domain" "$selector")"
        printf '%s' "$p" | fold -w 250 | while IFS= read -r chunk; do printf ' "%s"' "$chunk"; done
        echo " )"
        ;;
    list)
        printf 'domain\tselector\tactive\tkey\tbits\n'
        sed -n 's/^\([^ ]*\) \([^:]*\):\([^:]*\):\(.*\)$/\2 \3 \4/p' "$kt" | while read -r domain selector file; do
            act=no; [ "$(active_selector "$domain")" = "$selector" ] && act=yes
            key=missing; b=-
            if [ -s "$file" ]; then key=present; b="$(bits "$domain" "$selector")"; fi
            printf '%s\t%s\t%s\t%s\t%s\n' "$domain" "$selector" "$act" "$key" "$b"
        done
        ;;
    *) die "usage: generate|activate|retire|dns|pubkey|list (see the header of this script)" ;;
esac
