#!/usr/bin/env bash
# Installs the browser inspector's network isolation on the AI box. Run as root from this directory:
#
#   sudo ./install.sh --user daniel --dir /home/daniel/career-toolkit-browser-worker
#
# What it does: copies the controller script, the nftables policy and the systemd units into place, loads the policy
# (atomically) and starts its guard. What it does NOT do: start, stop, build or replace the worker or any container, restart
# Docker, or touch any firewall table other than its own. The worker unit is installed and enabled but not started; starting
# it is a separate, deliberate step (see README.md "Deployment sequence").
#
#   --user USER      the account that runs the worker (must be able to run docker)
#   --dir DIR        the worker's directory (holds compose.yaml and .env)
#   --root DIR       install under DIR instead of / and skip systemctl/nft (used by the tests)
#   --dry-run        print what would be done
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
USER_NAME=""; WORKER_DIR=""; ROOT=""; DRY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --user) USER_NAME=$2; shift 2 ;;
    --dir) WORKER_DIR=$2; shift 2 ;;
    --root) ROOT=$2; shift 2 ;;
    --dry-run) DRY=1; shift ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
done
[ -n "$USER_NAME" ] && [ -n "$WORKER_DIR" ] || { echo "--user and --dir are required" >&2; exit 2; }
case "$USER_NAME$WORKER_DIR" in *[$'\n'\ \&\|\;\\]*) echo "refusing a user or directory containing spaces or shell characters" >&2; exit 2 ;; esac

run() { if [ "$DRY" = 1 ]; then printf 'would: %s\n' "$*"; else "$@"; fi; }
step() { printf '==> %s\n' "$*"; }

if [ -z "$ROOT" ] && [ "$DRY" = 0 ] && [ "$(id -u)" -ne 0 ]; then echo "run as root (sudo)" >&2; exit 1; fi
for f in bin/inspector-isolation inspector-isolation.nft inspector-isolation.service inspector-isolation-guard.service \
         career-toolkit-browser-worker.service docker.service.d-inspector-isolation.conf; do
  [ -r "$HERE/$f" ] || { echo "missing $HERE/$f" >&2; exit 1; }
done
if [ -z "$ROOT" ]; then
  command -v nft >/dev/null || { echo "nft is not installed" >&2; exit 1; }
  command -v docker >/dev/null || { echo "docker is not installed" >&2; exit 1; }
  id "$USER_NAME" >/dev/null 2>&1 || { echo "no such user: $USER_NAME" >&2; exit 1; }
  [ -d "$WORKER_DIR" ] || { echo "no such directory: $WORKER_DIR" >&2; exit 1; }
  step "checking the policy loads (syntax and semantics only, nothing is changed)"
  run nft -c -f "$HERE/inspector-isolation.nft"
fi

SBIN=$ROOT/usr/local/sbin; CONF=$ROOT/etc/inspector-isolation; UNITS=$ROOT/etc/systemd/system; DOC=$ROOT/usr/share/doc/inspector-isolation
step "installing files"
run install -d -m 0755 "$SBIN" "$CONF" "$UNITS" "$UNITS/docker.service.d" "$DOC"
run install -m 0755 "$HERE/bin/inspector-isolation" "$SBIN/inspector-isolation"
run install -m 0644 "$HERE/inspector-isolation.nft" "$CONF/inspector-isolation.nft"
run install -m 0644 "$HERE/inspector-isolation.service" "$UNITS/inspector-isolation.service"
run install -m 0644 "$HERE/inspector-isolation-guard.service" "$UNITS/inspector-isolation-guard.service"
run install -m 0644 "$HERE/docker.service.d-inspector-isolation.conf" "$UNITS/docker.service.d/inspector-isolation.conf"
[ ! -r "$HERE/README.md" ] || run install -m 0644 "$HERE/README.md" "$DOC/README.md"
if [ "$DRY" = 1 ]; then
  echo "would: render career-toolkit-browser-worker.service for user=$USER_NAME dir=$WORKER_DIR"
else
  sed -e "s|@WORKER_USER@|$USER_NAME|g" -e "s|@WORKER_DIR@|$WORKER_DIR|g" "$HERE/career-toolkit-browser-worker.service" >"$UNITS/career-toolkit-browser-worker.service"
  chmod 0644 "$UNITS/career-toolkit-browser-worker.service"
fi

if [ -z "$ROOT" ]; then
  step "loading the policy and starting its guard"
  run systemctl daemon-reload
  run systemctl enable --now inspector-isolation.service
  run systemctl enable --now inspector-isolation-guard.service
  run systemctl enable career-toolkit-browser-worker.service
  step "verifying"
  run /usr/local/sbin/inspector-isolation verify
  cat <<MSG

Installed. The worker is NOT running and was not touched. Next (see README.md, "Deployment sequence"):
  1. stop and remove the OLD worker (it uses the old compose file and a different network),
  2. put the new worker files in $WORKER_DIR and build the image,
  3. sudo systemctl start career-toolkit-browser-worker
MSG
fi
