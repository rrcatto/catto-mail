#!/bin/sh
# smarthost-postfix-control: the operator's emergency outbound pause (Phase 8).
#
#   pause [reason]   stop all outbound delivery now: defer_transports=smtp, so
#                    accepted mail stays in the Postfix queue (nothing is lost or
#                    bounced); the flag survives container restarts, and the Go
#                    delivery daemon, which watches the same flag read-only, stops
#                    claiming send jobs and starting new submissions
#   resume           lift the pause and flush the queue (postqueue -f)
#   status           paused or not, the flag's reason/time, and queue depth
#
# Run inside the Postfix container (smarthostctl prod pause|resume|pause-status).
# Inbound port 25 (DSNs, ARF reports) keeps working while paused.
set -eu
obs="${SMARTHOST_POSTFIX_OBSERVABILITY_DIR:?}"
flag="$obs/control/outbound-paused"

queue_summary() {
    postqueue -j 2>/dev/null | awk -F'"queue_name": *"' 'NF > 1 { split($2, a, "\""); n[a[1]]++ } END {
        printf "queue: active=%d deferred=%d hold=%d incoming=%d\n", n["active"], n["deferred"], n["hold"], n["incoming"] + n["maildrop"] }'
}

case "${1:-status}" in
    pause)
        shift || true
        mkdir -p "$obs/control"
        printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${*:-no reason given}" > "$flag"
        chmod 0640 "$flag"
        postconf -e 'defer_transports = smtp'
        postfix reload >/dev/null 2>&1 || true
        echo "outbound PAUSED: nothing leaves the queue; the delivery daemon stops new submissions"
        queue_summary
        ;;
    resume)
        rm -f "$flag"
        postconf -e 'defer_transports ='
        postfix reload >/dev/null 2>&1 || true
        postqueue -f
        echo "outbound RESUMED: the queue is being flushed; the delivery daemon resumes within seconds"
        queue_summary
        ;;
    status)
        if [ -e "$flag" ]; then echo "outbound: PAUSED since $(cat "$flag")"; else echo "outbound: not paused"; fi
        echo "defer_transports: $(postconf -h defer_transports)"
        echo "default_transport: $(postconf -h default_transport)"
        echo "relayhost: $(postconf -h relayhost)"
        queue_summary
        ;;
    *) echo "usage: smarthost-postfix-control pause [reason]|resume|status" >&2; exit 64 ;;
esac
