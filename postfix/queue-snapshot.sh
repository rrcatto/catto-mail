#!/bin/sh
# Atomic Postfix queue snapshot (spec transport_reconciliation; D-27).
# Run by the smarthost-postfix-queue-snapshot systemd user timer via
# `podman exec`. Writes JSON Lines from `postqueue -j` to a temporary file in
# the same directory and renames it only after a successful exit, so readers
# never observe a partial file and an empty file genuinely means an empty queue.
set -eu
dir="${SMARTHOST_POSTFIX_OBSERVABILITY_DIR:?}/queue"
keep="${POSTFIX_QUEUE_SNAPSHOT_RETENTION_COUNT:?}"
ts=$(date -u +%Y%m%dT%H%M%SZ)
umask 027
tmp="$dir/.tmp-$ts.$$"
if postqueue -j > "$tmp"; then
    mv "$tmp" "$dir/snapshot-$ts.jsonl"
else
    rc=$?; rm -f "$tmp"; echo "postqueue -j failed ($rc); no snapshot written" >&2; exit "$rc"
fi
# Retention: keep the newest $keep snapshots (names sort chronologically).
ls -1 "$dir"/snapshot-*.jsonl 2>/dev/null | sort | head -n "-$keep" | xargs -r rm -f
