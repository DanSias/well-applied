#!/usr/bin/env bash
# Removes the browser inspector's network isolation. Run as root:
#
#   sudo ./rollback.sh [--purge]
#
# Stops the isolated worker (if running), the guard and the policy, removes the units, the drop-in, the script and the
# policy file, and deletes the nftables table. It does not remove Docker networks or images, and it does not start any
# worker: restoring the OLD worker is a separate step (see README.md "Rollback"). Safe to run twice.
#
#   --purge          also remove the recorded fingerprint and the (empty) config directory
#   --root DIR       operate under DIR and skip systemctl/nft (used by the tests)
set -euo pipefail
ROOT=""; PURGE=0
while [ $# -gt 0 ]; do
  case "$1" in
    --purge) PURGE=1; shift ;;
    --root) ROOT=$2; shift 2 ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
done
if [ -z "$ROOT" ] && [ "$(id -u)" -ne 0 ]; then echo "run as root (sudo)" >&2; exit 1; fi
step() { printf '==> %s\n' "$*"; }

if [ -z "$ROOT" ]; then
  step "stopping the worker, the guard and the policy (in that order)"
  systemctl disable --now career-toolkit-browser-worker.service 2>/dev/null || true
  systemctl disable --now inspector-isolation-guard.service 2>/dev/null || true
  systemctl disable --now inspector-isolation.service 2>/dev/null || true
fi

step "removing files"
rm -f "$ROOT/etc/systemd/system/career-toolkit-browser-worker.service" \
      "$ROOT/etc/systemd/system/inspector-isolation-guard.service" \
      "$ROOT/etc/systemd/system/inspector-isolation.service" \
      "$ROOT/etc/systemd/system/docker.service.d/inspector-isolation.conf" \
      "$ROOT/usr/local/sbin/inspector-isolation" \
      "$ROOT/etc/inspector-isolation/inspector-isolation.nft" \
      "$ROOT/usr/share/doc/inspector-isolation/README.md"
rmdir "$ROOT/etc/systemd/system/docker.service.d" "$ROOT/usr/share/doc/inspector-isolation" 2>/dev/null || true
if [ "$PURGE" = 1 ]; then
  rm -rf "$ROOT/var/lib/inspector-isolation"
  rmdir "$ROOT/etc/inspector-isolation" 2>/dev/null || true
fi

if [ -z "$ROOT" ]; then
  nft delete table inet inspector_isolation 2>/dev/null || true   # in case the unit's own stop did not run
  systemctl daemon-reload
  systemctl reset-failed 'inspector-*' 'career-toolkit-browser-worker*' 2>/dev/null || true
  cat <<MSG

Isolation removed. Docker, Tailscale and every other container were not touched. To go back to the previous worker see
README.md "Rollback": bring the old compose file and image back and start it the way it was started before.
MSG
fi
