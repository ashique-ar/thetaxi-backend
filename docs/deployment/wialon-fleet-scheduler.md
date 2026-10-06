# Wialon fleet sync

Casons and TheTaxi are deployed on separate servers. Deploy the same backend and portal feature branches to both. Each server needs its own PHP 8.5 CLI scheduler running from that server's app directory, so Laravel uses that installation's `.env` and database. Wialon tokens and resource/group selections are stored per company in the database; no Wialon token belongs in `.env`.

The Bitnami PHP binary reported on the Casons server is PHP 8.3.22, while this release requires PHP 8.5. On each Ubuntu server, install PHP 8.5 CLI and the extensions used by the app. This installs a separate CLI version and leaves Bitnami's PHP binary unchanged:

```sh
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.5-cli php8.5-pgsql php8.5-mbstring php8.5-gd php8.5-bcmath php8.5-zip php8.5-redis
/usr/bin/php8.5 -v
```

On the Casons server use `/home/bitnami/htdocs/casons-public`; on the TheTaxi server use `/home/bitnami/htdocs/thetaxi-public`. Run the rest of this block separately on each server, after changing into its path:

```sh
cd /home/bitnami/htdocs/casons-public
app_dir="$(pwd -P)"
php_bin=/usr/bin/php8.5
service_group="$(id -gn bitnami)"
test -f artisan && "$php_bin" artisan about

for migration in database/migrations/2026_10_06_00000{1..6}_*.php; do
    "$php_bin" artisan migrate --path="$migration" --force
done

sudo tee /etc/systemd/system/thetaxi-wialon-scheduler.service >/dev/null <<EOF
[Unit]
Description=TheTaxi Wialon fleet synchronization scheduler
After=network.target
ConditionPathExists=$app_dir/artisan

[Service]
Type=simple
User=bitnami
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

sudo systemctl daemon-reload
sudo systemctl enable thetaxi-wialon-scheduler
sudo systemctl restart thetaxi-wialon-scheduler
sudo systemctl status thetaxi-wialon-scheduler
journalctl -u thetaxi-wialon-scheduler -n 50 --no-pager
```

On the TheTaxi server, change only the `cd` line to `cd /home/bitnami/htdocs/thetaxi-public` before running the block. Use one scheduler per server/database. Configure each company's token, Wialon resources, selected unit groups/units, and TheTaxi vehicle-group mappings in **Vehicles > Live Tracking**. The scheduler syncs selected Wialon units every five minutes. Keep the web runtime on PHP 8.5 as well, because this release's Composer dependencies require PHP 8.5.

For other hosts using systemd, render `deploy/systemd/thetaxi-wialon-scheduler.service.example` with that host's absolute app path, service user/group, and PHP 8.5 binary. Do not install the example with its placeholders unchanged.
