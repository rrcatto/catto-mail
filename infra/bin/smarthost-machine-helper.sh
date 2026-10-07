#!/bin/sh
# Runs INSIDE the Podman host's systemd namespace (directly on a Linux host, or
# via the Podman machine's `enterns` on WSL). Called by smarthostctl; it takes
# no shell-expanded arguments from Windows, so it is safe to invoke via wsl.exe.
#
# It manages only the systemd user units around the persistent `smarthost` pod
# (D-35): `smarthost.service` (start/stop at boot/shutdown, linked into
# default.target.wants) and the two Postfix timers. In production (Phase 8) the
# rendered units also include the instance's own production units
# (<instance>-ingress.socket/.service, the socket-activated nginx;
# <instance>-reputation-evaluate.service/.timer). `instance-install`/`instance-uninstall`
# handle only those, for a production instance other than the default one (the local
# rehearsal). It never creates or removes Podman objects.
set -eu
export XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"
GENERATED="$(cd "$(dirname "$0")/../.generated" && pwd)"
# ~/.config/systemd/user is root-owned in the Podman machine image (machine
# provisioning); ~/.local/share/systemd/user is on the systemd user search path.
UNIT_DIR="$HOME/.local/share/systemd/user"
WANTS="$UNIT_DIR/default.target.wants"
# Before D-35 the topology ran from Quadlet units (smarthost.target + generated
# services), which delete their containers and pod on stop.
LEGACY_QUADLET_DIR="$HOME/.config/containers/systemd"

remove_legacy_quadlet() {
    if ls "$LEGACY_QUADLET_DIR"/smarthost* >/dev/null 2>&1 || [ -e "$UNIT_DIR/smarthost.target" ]; then
        systemctl --user stop smarthost.target 2>/dev/null || true
        rm -f "$LEGACY_QUADLET_DIR"/smarthost* "$UNIT_DIR/smarthost.target" "$WANTS/smarthost.target"
        systemctl --user daemon-reload
        systemctl --user reset-failed 'smarthost*' 2>/dev/null || true
        echo "removed the legacy Quadlet units (Podman volumes and the network are untouched)"
    fi
}

action="${1:?action}"; shift
case "$action" in
    install)
        remove_legacy_quadlet
        mkdir -p "$UNIT_DIR" "$WANTS"
        rm -f "$UNIT_DIR"/smarthost.service "$UNIT_DIR"/smarthost-*
        cp "$GENERATED"/systemd/* "$UNIT_DIR"/
        # Start with the user manager (machine boot). This is what
        # `systemctl --user enable` would do, but that writes to the root-owned
        # ~/.config/systemd/user; .wants directories on any unit path count.
        ln -sfn ../smarthost.service "$WANTS/smarthost.service"
        systemctl --user daemon-reload
        echo "installed $(ls "$GENERATED"/systemd | wc -l) systemd units (smarthost.service starts the pod at boot)"
        ;;
    uninstall)
        remove_legacy_quadlet
        rm -f "$UNIT_DIR"/smarthost.service "$UNIT_DIR"/smarthost-* "$WANTS/smarthost.service"
        systemctl --user daemon-reload
        echo "removed the systemd units; the pod, its containers and the volumes are untouched"
        ;;
    instance-install)
        # instance-install <generated dir under infra/.generated> <instance>: the instance's production units
        dir="$(cd "${1:?generated dir}" && pwd)"; inst="${2:?instance}"
        case "$dir" in "$GENERATED"|"$GENERATED"/*) ;; *) echo "not under $GENERATED: $dir" >&2; exit 64 ;; esac
        case "$inst" in *[!a-z0-9-]*|"") echo "bad instance name" >&2; exit 64 ;; esac
        mkdir -p "$UNIT_DIR"
        n=0
        for unit in "$dir/systemd/$inst"-*; do
            [ -e "$unit" ] || continue
            cp "$unit" "$UNIT_DIR"/; n=$((n + 1))
        done
        systemctl --user daemon-reload
        echo "installed $n units of instance $inst"
        ;;
    instance-uninstall)
        inst="${1:?instance}"
        case "$inst" in *[!a-z0-9-]*|"") echo "bad instance name" >&2; exit 64 ;; esac
        for unit in "$UNIT_DIR/$inst"-*; do
            [ -e "$unit" ] || continue
            systemctl --user stop "$(basename "$unit")" 2>/dev/null || true
            rm -f "$unit"
        done
        systemctl --user daemon-reload
        systemctl --user reset-failed "$inst-*" 2>/dev/null || true
        echo "removed the units of instance $inst"
        ;;
    systemctl) exec systemctl --user "$@" ;;
    journal) exec journalctl --user --no-pager "$@" ;;
    *) echo "unknown action $action" >&2; exit 64 ;;
esac
