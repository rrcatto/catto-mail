#!/bin/sh
# Daily Postfix log rotation (postfix logrotate, Postfix >= 3.4) plus deletion
# of rotated files older than POSTFIX_LOG_RETENTION_DAYS. Run by the
# smarthost-postfix-logrotate systemd user timer via `podman exec`.
set -eu
dir="${SMARTHOST_POSTFIX_OBSERVABILITY_DIR:?}/log"
days="${POSTFIX_LOG_RETENTION_DAYS:?}"
postfix logrotate
find "$dir" -maxdepth 1 -type f -name 'postfix.log.*' -mtime "+$days" -delete
