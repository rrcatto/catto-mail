#!/bin/sh
# Smarthost Postfix entrypoint.
#
# Builds main.cf/master.cf from the environment contract
# (docs/contracts/environment.md) and starts Postfix in the foreground.
#
# Safety (spec: podman_environment, SMARTHOST_LIVE_DELIVERY_ENABLED):
#   * capture mode (development/test, the default): every non-local message is
#     relayed to POSTFIX_RELAYHOST (Mailpit). Startup fails if no relayhost is set.
#   * held mode (production before live activation, specification 2.9): no
#     relayhost; the default transport is retry(8), so smtpd refuses outbound
#     recipients temporarily (450 "live delivery is not activated") and anything
#     queued stays deferred, except the web application's own sign-in mail (APP_MAIL_FROM),
#     so operators can sign in to the dashboard before activation. The Go
#     daemon claims no send jobs in this state.
#   * live mode: only when SMARTHOST_LIVE_DELIVERY_ENABLED=true AND
#     SMARTHOST_ENV=production. Any other combination refuses to start.
#   * Production never has a relayhost (no Mailpit, no capture relay).
#   * Emergency pause (any mode): the flag $SMARTHOST_POSTFIX_OBSERVABILITY_DIR/
#     control/outbound-paused adds defer_transports=smtp, so nothing leaves the
#     queue; smarthost-postfix-control sets and clears it at runtime.
#   * Production inbound SMTP (Phase 8 networking): port 25 listens only on
#     postfix-ingress (the ingress network, shared with nginx alone) and requires
#     the PROXY protocol: nginx inherits the public socket from systemd and sends
#     each client's real address in the PROXY header. A container-local
#     127.0.0.1:25 serves the health check. Development keeps the plain port 25.
# In development the container additionally sits on an Internal=true Podman
# network with no route to the Internet (defence in depth).
set -eu

die() { echo "smarthost-postfix: FATAL: $*" >&2; exit 64; }
log() { echo "smarthost-postfix: $*"; }

# Contract rule 2: any secret X may be supplied as X_FILE.
file_env() {
    var="$1"; file_var="${1}_FILE"
    eval "val=\${$var:-}"; eval "fval=\${$file_var:-}"
    if [ -n "$val" ] && [ -n "$fval" ]; then die "both $var and $file_var are set"; fi
    if [ -n "$fval" ]; then val=$(cat "$fval"); export "$var=$val"; fi
}
require() { for v in "$@"; do eval "test -n \"\${$v:-}\"" || die "required variable $v is not set"; done; }

file_env SMARTHOST_SUBMISSION_PASSWORD
file_env APP_MAIL_SUBMISSION_PASSWORD
require SMARTHOST_ENV SMARTHOST_LIVE_DELIVERY_ENABLED SMARTHOST_BOUNCE_DOMAIN SMARTHOST_VERP_LOCAL_PART \
        SMARTHOST_VERP_DELIMITER SMARTHOST_SUBMISSION_USERNAME SMARTHOST_SUBMISSION_PASSWORD \
        APP_MAIL_SUBMISSION_USERNAME APP_MAIL_SUBMISSION_PASSWORD \
        SMARTHOST_POSTFIX_OBSERVABILITY_DIR SMARTHOST_DSN_SPOOL_DIR SMARTHOST_SPOOL_GID SMARTHOST_DELIVERY_UID \
        SMARTHOST_OPENDKIM_MILTER_ADDRESS POSTFIX_MYHOSTNAME POSTFIX_TLS_CERT_FILE POSTFIX_TLS_KEY_FILE \
        POSTFIX_MESSAGE_SIZE_LIMIT APP_MAIL_FROM

# ---------------------------------------------------------------- safety switch
default_transport=smtp
held_senders=
case "$SMARTHOST_LIVE_DELIVERY_ENABLED" in
    false)
        if [ "$SMARTHOST_ENV" = "production" ]; then
            [ -z "${POSTFIX_RELAYHOST:-}" ] || die "production must not set POSTFIX_RELAYHOST (no capture relay in production)"
            relayhost=""
            default_transport="retry:live delivery is not activated"
            held_senders="$APP_MAIL_FROM"
            log "HELD MODE (production, live delivery not activated): outbound recipients are refused temporarily (450); only $APP_MAIL_FROM (dashboard sign-in) is delivered"
        else
            [ -n "${POSTFIX_RELAYHOST:-}" ] || die "capture mode requires POSTFIX_RELAYHOST (Mailpit); refusing to start"
            relayhost="$POSTFIX_RELAYHOST"
            log "CAPTURE MODE: all outbound mail is relayed to $relayhost; no Internet delivery"
        fi
        ;;
    true)
        [ "$SMARTHOST_ENV" = "production" ] || die "live delivery is only permitted with SMARTHOST_ENV=production (got '$SMARTHOST_ENV')"
        [ -z "${POSTFIX_RELAYHOST:-}" ] || die "live mode must not set POSTFIX_RELAYHOST"
        relayhost=""
        log "LIVE MODE: outbound mail is delivered to the Internet"
        ;;
    *) die "SMARTHOST_LIVE_DELIVERY_ENABLED must be 'true' or 'false'" ;;
esac

obs="$SMARTHOST_POSTFIX_OBSERVABILITY_DIR"
spool="$SMARTHOST_DSN_SPOOL_DIR"
gid="$SMARTHOST_SPOOL_GID"
duid="$SMARTHOST_DELIVERY_UID"

# ------------------------------------------------- shared volume layout (V-2/V-4/V-5)
umask 027
mkdir -p "$obs/log" "$obs/queue" "$obs/control"
chgrp "$gid" "$obs" "$obs/log" "$obs/queue" "$obs/control"
chmod 2750 "$obs" "$obs/log" "$obs/queue" "$obs/control"   # setgid: new files inherit the spool group
held_map=
if [ -n "$held_senders" ]; then held_map="inline:{ $held_senders=smtp: }"; fi
pause_flag="$obs/control/outbound-paused"
defer_transports=
if [ -e "$pause_flag" ]; then
    defer_transports=smtp
    log "EMERGENCY PAUSE in force ($pause_flag): outbound mail stays in the queue (smarthost-postfix-control resume)"
fi

mkdir -p "$spool/processing" "$spool/done" "$spool/failed"
chown "$duid:$gid" "$spool" "$spool/processing" "$spool/done" "$spool/failed"
chmod 2770 "$spool" "$spool/processing" "$spool/done" "$spool/failed"
# inbound/ (Maildir) is created by virtual(8) on first delivery, owned by $duid.
# Configuration files below must be readable by unprivileged Postfix daemons:
# smtpd (postfix user) reads the SASL config; virtual(8) runs as $duid and reads
# the bounce-recipient table. Secrets (sasldb2) get explicit modes.
umask 022

# ---------------------------------------------------------------------- main.cf
cat > /etc/postfix/main.cf <<EOF
# Generated by smarthost-postfix-entrypoint; do not edit inside the container.
compatibility_level = 3.10
myhostname = $POSTFIX_MYHOSTNAME
myorigin = \$myhostname
mydestination =
inet_interfaces = all
inet_protocols = ipv4
# Only loopback is trusted; container peers must authenticate on 587.
mynetworks = 127.0.0.0/8
relayhost = $relayhost
smtp_fallback_relay =
transport_maps =
default_transport = $default_transport
# Held mode: the dashboard sign-in sender keeps the normal smtp transport.
sender_dependent_default_transport_maps = $held_map
# Emergency pause (control/outbound-paused): nothing leaves the queue.
defer_transports = $defer_transports
enable_long_queue_ids = yes
message_size_limit = $POSTFIX_MESSAGE_SIZE_LIMIT
recipient_delimiter = $SMARTHOST_VERP_DELIMITER
biff = no
# No local(8) delivery exists (mydestination is empty), so no alias database.
alias_maps =
alias_database =
append_dot_mydomain = no

# Logging into the shared observability volume (Go reads it read-only).
maillog_file = $obs/log/postfix.log
maillog_file_permissions = 0640

# Bounce domain: VERP and postmaster recipients only, delivered to the DSN Maildir
# as the delivery identity so Go can read and claim the files.
virtual_mailbox_domains = $SMARTHOST_BOUNCE_DOMAIN
virtual_mailbox_base = $spool
virtual_mailbox_maps = regexp:/etc/postfix/smarthost_bounce_recipients
virtual_uid_maps = static:$duid
virtual_gid_maps = static:$gid
virtual_minimum_uid = 100
# Port 25 never relays, whatever the client address: in the development pod every
# container reaches Postfix from 127.0.0.1, which is in mynetworks. It accepts only
# bounce-domain recipients (DSNs, ARF reports, postmaster). In production the
# client address is the real one (PROXY protocol from nginx, see master.cf below).
smtpd_relay_restrictions = reject_unauth_destination
smtpd_recipient_restrictions = reject_unauth_destination
smtpd_helo_required = yes
disable_vrfy_command = yes
smtpd_banner = \$myhostname ESMTP
# Each submission account may only use its own envelope senders (specification 2.9):
# the delivery daemon its VERP return paths, the web application APP_MAIL_FROM.
smtpd_sender_login_maps = regexp:/etc/postfix/smarthost_sender_logins

# TLS
smtpd_tls_cert_file = $POSTFIX_TLS_CERT_FILE
smtpd_tls_key_file = $POSTFIX_TLS_KEY_FILE
smtpd_tls_security_level = may
smtp_tls_security_level = may

# SASL for the submission service (Cyrus sasldb).
smtpd_sasl_type = cyrus
smtpd_sasl_path = smtpd
# Debian's Cyrus SASL does not search /etc/postfix/sasl by default (observed Phase 1).
cyrus_sasl_config_path = /etc/postfix/sasl
smtpd_sasl_local_domain = \$myhostname
smtpd_sasl_auth_enable = no

# Milters: none globally. OpenDKIM is attached to the submission service only;
# inbound port-25 DSN traffic and Postfix-generated mail are never signed.
smtpd_milters =
non_smtpd_milters =
milter_default_action = tempfail
EOF

# Bounce-domain recipients accepted on port 25 (everything else is rejected).
escape() { printf '%s' "$1" | sed 's/[.[\*^$+?(){}|/]/\\&/g'; }
bd=$(escape "$SMARTHOST_BOUNCE_DOMAIN")
lp=$(escape "$SMARTHOST_VERP_LOCAL_PART")
dl=$(escape "$SMARTHOST_VERP_DELIMITER")
cat > /etc/postfix/smarthost_bounce_recipients <<EOF
/^${lp}(${dl}[^@]+)?@${bd}\$/   inbound/
/^postmaster@${bd}\$/           inbound/
EOF
# Envelope-sender owners on submission (SASL login names carry the realm).
af=$(escape "$APP_MAIL_FROM")
cat > /etc/postfix/smarthost_sender_logins <<EOF
/^${lp}(${dl}[^@]+)?@${bd}\$/   ${SMARTHOST_SUBMISSION_USERNAME}@${POSTFIX_MYHOSTNAME}
/^${af}\$/                      ${APP_MAIL_SUBMISSION_USERNAME}@${POSTFIX_MYHOSTNAME}
EOF

# -------------------------------------------------------------------- master.cf
postconf -F '*/*/chroot = n'
if [ "$SMARTHOST_ENV" = "production" ]; then
    # Inbound SMTP arrives only from nginx (ingress socket -> PROXY protocol), on the
    # ingress-network address the production topology names postfix-ingress.
    getent hosts postfix-ingress >/dev/null \
        || die "production needs the ingress network address postfix-ingress (smarthost-production.sh creates it)"
    postconf -MX smtp/inet
    postconf -M "127.0.0.1:smtp/inet=127.0.0.1:smtp inet n - n - - smtpd" \
                "postfix-ingress:smtp/inet=postfix-ingress:smtp inet n - n - - smtpd"
    postconf -P "postfix-ingress:smtp/inet/smtpd_upstream_proxy_protocol=haproxy"
    log "inbound SMTP: postfix-ingress:25 (PROXY protocol from nginx: real client addresses) and 127.0.0.1:25 (health check)"
fi
postconf -M "submission/inet=submission inet n - n - - smtpd"
postconf -P \
    "submission/inet/syslog_name=postfix/submission" \
    "submission/inet/smtpd_tls_security_level=encrypt" \
    "submission/inet/smtpd_sasl_auth_enable=yes" \
    "submission/inet/smtpd_tls_auth_only=yes" \
    "submission/inet/smtpd_client_restrictions=permit_sasl_authenticated,reject" \
    "submission/inet/smtpd_relay_restrictions=permit_sasl_authenticated,reject" \
    "submission/inet/smtpd_recipient_restrictions=permit_sasl_authenticated,reject" \
    "submission/inet/smtpd_sender_restrictions=reject_sender_login_mismatch,permit_sasl_authenticated,reject" \
    "submission/inet/smtpd_milters=$SMARTHOST_OPENDKIM_MILTER_ADDRESS" \
    "submission/inet/milter_default_action=tempfail" \
    "submission/inet/milter_macro_daemon_name=ORIGINATING"

# ------------------------------------------------------------------------- SASL
mkdir -p /etc/postfix/sasl
cat > /etc/postfix/sasl/smtpd.conf <<EOF
pwcheck_method: auxprop
auxprop_plugin: sasldb
mech_list: PLAIN LOGIN
sasldb_path: /etc/postfix/sasl/sasldb2
EOF
rm -f /etc/postfix/sasl/sasldb2
# Two submission accounts: the Go delivery daemon and the Symfony web application
# (dashboard sign-in links). Both go through the OpenDKIM milter.
[ "$APP_MAIL_SUBMISSION_USERNAME" != "$SMARTHOST_SUBMISSION_USERNAME" ] || die "APP_MAIL_SUBMISSION_USERNAME must differ from SMARTHOST_SUBMISSION_USERNAME"
printf '%s' "$SMARTHOST_SUBMISSION_PASSWORD" | \
    saslpasswd2 -p -c -f /etc/postfix/sasl/sasldb2 -u "$POSTFIX_MYHOSTNAME" "$SMARTHOST_SUBMISSION_USERNAME"
printf '%s' "$APP_MAIL_SUBMISSION_PASSWORD" | \
    saslpasswd2 -p -c -f /etc/postfix/sasl/sasldb2 -u "$POSTFIX_MYHOSTNAME" "$APP_MAIL_SUBMISSION_USERNAME"
chown root:postfix /etc/postfix/sasl/sasldb2
chmod 0640 /etc/postfix/sasl/sasldb2

[ -r "$POSTFIX_TLS_CERT_FILE" ] || die "TLS certificate $POSTFIX_TLS_CERT_FILE not readable"
[ -r "$POSTFIX_TLS_KEY_FILE" ] || die "TLS key $POSTFIX_TLS_KEY_FILE not readable"

postmap -q "x@$SMARTHOST_BOUNCE_DOMAIN" regexp:/etc/postfix/smarthost_bounce_recipients >/dev/null || true
postfix check
log "configuration written; starting Postfix $(postconf -h mail_version) in the foreground"
exec postfix start-fg
