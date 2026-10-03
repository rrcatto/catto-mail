#!/bin/sh
# Runs INSIDE the Podman host's systemd namespace (directly on a Linux host, or
# via the Podman machine's `enterns` on WSL). Called by smarthostctl; it takes
# no shell-expanded arguments from Windows, so it is safe to invoke via wsl.exe.
set -eu
export XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"
GENERATED="$(cd "$(dirname "$0")/../.generated" && pwd)"
QUADLET_DIR="$HOME/.config/containers/systemd"
# ~/.config/systemd/user is root-owned in the Podman machine image (machine
# provisioning); ~/.local/share/systemd/user is on the systemd user search path.
UNIT_DIR="$HOME/.local/share/systemd/user"
action="${1:?action}"; shift
case "$action" in
    install)
        mkdir -p "$QUADLET_DIR" "$UNIT_DIR"
        rm -f "$QUADLET_DIR"/smarthost-* "$UNIT_DIR"/smarthost.target "$UNIT_DIR"/smarthost-*
        cp "$GENERATED"/quadlet/* "$QUADLET_DIR"/
        cp "$GENERATED"/systemd/* "$UNIT_DIR"/
        # Start the topology with the user manager (machine boot). This is what
        # `systemctl --user enable` would do, but that writes to the root-owned
        # ~/.config/systemd/user; .wants directories on any unit path count.
        mkdir -p "$UNIT_DIR"/default.target.wants
        ln -sfn ../smarthost.target "$UNIT_DIR"/default.target.wants/smarthost.target
        out="$(/usr/libexec/podman/quadlet -dryrun -user 2>&1 >/dev/null)" || { echo "$out" >&2; exit 1; }
        systemctl --user daemon-reload
        echo "installed $(ls "$GENERATED"/quadlet | wc -l) Quadlet files and $(ls "$GENERATED"/systemd | wc -l) systemd units (smarthost.target starts at boot)"
        ;;
    uninstall)
        systemctl --user stop smarthost.target 2>/dev/null || true
        rm -f "$QUADLET_DIR"/smarthost-* "$UNIT_DIR"/smarthost.target "$UNIT_DIR"/smarthost-* \
            "$UNIT_DIR"/default.target.wants/smarthost.target
        systemctl --user daemon-reload
        ;;
    quadlet-dryrun) /usr/libexec/podman/quadlet -dryrun -user ;;
    systemctl) exec systemctl --user "$@" ;;
    journal) exec journalctl --user --no-pager "$@" ;;
    *) echo "unknown action $action" >&2; exit 64 ;;
esac
