#!/usr/bin/env bash
set -euo pipefail

usage() {
    echo "Usage: $0 <systemd-unit-name> [deployment-directory]" >&2
    exit 64
}

UNIT_NAME="${1:-}"
DEPLOYMENT_DIR="${2:-$(pwd)}"

[[ -n "$UNIT_NAME" ]] || usage
[[ "$UNIT_NAME" =~ ^[A-Za-z0-9_.@-]+(\.service)?$ ]] || {
    echo "Invalid systemd unit name." >&2
    exit 64
}

DEPLOYMENT_DIR="$(readlink -f -- "$DEPLOYMENT_DIR")"
[[ -d "$DEPLOYMENT_DIR" ]] || {
    echo "Deployment directory does not exist: $DEPLOYMENT_DIR" >&2
    exit 66
}

echo "SMS production service audit (read-only)"
echo "Generated: $(date --iso-8601=seconds)"
echo "Host: $(hostname)"
echo "Requested unit: $UNIT_NAME"
echo "Requested deployment: $DEPLOYMENT_DIR"

echo
echo "Effective systemd properties"
systemctl show "$UNIT_NAME" \
    --property=Id,LoadState,ActiveState,SubState,FragmentPath,DropInPaths,User,Group,WorkingDirectory,ExecStart,MainPID \
    --no-pager

echo
echo "Effective unit definition"
systemctl cat "$UNIT_NAME" --no-pager

MAIN_PID="$(systemctl show "$UNIT_NAME" --property=MainPID --value --no-pager)"
if [[ "$MAIN_PID" =~ ^[1-9][0-9]*$ ]]; then
    echo
    echo "Active process"
    ps -o pid=,ppid=,user=,group=,lstart=,args= -p "$MAIN_PID"
else
    echo
    echo "Active process: none"
fi

echo
echo "Deployment runtime"
if [[ -x "$DEPLOYMENT_DIR/artisan" || -f "$DEPLOYMENT_DIR/artisan" ]]; then
    echo "artisan: $DEPLOYMENT_DIR/artisan"
else
    echo "artisan: missing"
fi
if command -v php >/dev/null 2>&1; then
    echo "shell php: $(command -v php)"
    php --version | head -n 1
else
    echo "shell php: missing"
fi

echo
echo "Storage permissions"
for TARGET in "$DEPLOYMENT_DIR/storage" "$DEPLOYMENT_DIR/storage/logs" "$DEPLOYMENT_DIR/bootstrap/cache"; do
    if [[ -e "$TARGET" ]]; then
        stat --format='%A %a %U:%G %n' -- "$TARGET"
    else
        echo "missing $TARGET"
    fi
done

echo
echo "Safety result"
echo "No service state, queue job, application record, or provider state was changed."
echo "Next: run the project command 'php artisan sms:audit-readiness --json' with the verified PHP binary and working directory."
