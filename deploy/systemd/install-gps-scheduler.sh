#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 3 || $EUID -ne 0 ]]; then
    echo "Usage: sudo $0 APP_DIRECTORY PHP_8_5_BINARY SERVICE_USER" >&2
    exit 2
fi

app_dir="$(realpath "$1")"
php_bin="$(realpath "$2")"
service_user="$3"

[[ -f "$app_dir/artisan" ]] || { echo "Laravel artisan file not found in $app_dir" >&2; exit 1; }
[[ -x "$php_bin" ]] || { echo "PHP binary is not executable: $php_bin" >&2; exit 1; }
[[ "$app_dir" != *$'\n'* && "$app_dir" != *' '* ]] || { echo "App path must not contain spaces or newlines." >&2; exit 1; }
[[ "$php_bin" != *$'\n'* && "$php_bin" != *' '* ]] || { echo "PHP path must not contain spaces or newlines." >&2; exit 1; }
id "$service_user" >/dev/null
service_group="$(id -gn "$service_user")"
if ! "$php_bin" -r 'exit(PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 90000 ? 0 : 1);'; then
    php_version="$("$php_bin" -r 'echo PHP_VERSION;')"
    echo "This application requires PHP 8.5; selected binary reports $php_version." >&2
    exit 1
fi
if ! runuser -u "$service_user" -- "$php_bin" "$app_dir/artisan" about >/dev/null; then
    echo "Laravel could not boot with $php_bin from $app_dir." >&2
    exit 1
fi

unit_name=gps-fleet-scheduler
unit_file="/etc/systemd/system/$unit_name.service"
legacy_unit=thetaxi-wialon-scheduler.service
if [[ -e "/etc/systemd/system/$legacy_unit" || -L "/etc/systemd/system/$legacy_unit" ]]; then
    systemctl disable --now "$legacy_unit" || true
fi

cat >"$unit_file" <<EOF
[Unit]
Description=GPS fleet synchronization scheduler
After=network.target
ConditionPathExists=$app_dir/artisan

[Service]
Type=simple
User=$service_user
Group=$service_group
WorkingDirectory=$app_dir
ExecStart=$php_bin artisan schedule:work
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=30
StandardOutput=journal
StandardError=journal
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths=$app_dir/storage $app_dir/bootstrap/cache

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable "$unit_name.service"
systemctl restart "$unit_name.service"
systemctl status "$unit_name.service" --no-pager
journalctl -u "$unit_name.service" -n 30 --no-pager
