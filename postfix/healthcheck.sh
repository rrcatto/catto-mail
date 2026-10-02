#!/bin/sh
# Readiness (not mere process existence): the master is running AND both
# listeners answer with an SMTP 220 greeting. We wait for the greeting before
# sending QUIT; sending first triggers Postfix's pipelining protection (554).
set -eu
postfix status >/dev/null 2>&1
for port in 25 587; do
    { sleep 1; printf 'QUIT\r\n'; } | nc -w 3 127.0.0.1 "$port" | head -n 1 | grep -q '^220 ' || exit 1
done
