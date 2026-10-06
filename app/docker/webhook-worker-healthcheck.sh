#!/bin/sh
# Healthy only if the real webhook worker loop touched its liveness file within the
# last 120 seconds (it does so every loop iteration and every second while idle).
f=/tmp/smarthost-webhook-worker.alive
[ -f "$f" ] || exit 1
age=$(( $(date +%s) - $(stat -c %Y "$f") ))
[ "$age" -lt 120 ]
