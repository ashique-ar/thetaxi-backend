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

Run the following on each server. Use `/home/bitnami/htdocs/casons-public` on Casons or `/home/bitnami/htdocs/thetaxi-public` on TheTaxi:

```sh
app_dir=/home/bitnami/htdocs/casons-public # use /home/bitnami/htdocs/thetaxi-public on TheTaxi
cd "$app_dir"
php_bin=/usr/bin/php8.5
test -f artisan && "$php_bin" artisan about

for migration in database/migrations/2026_10_06_00000{1..6}_*.php; do
    "$php_bin" artisan migrate --path="$migration" --force
done

sudo bash deploy/systemd/install-wialon-scheduler.sh "$app_dir" "$php_bin" bitnami
```

Use one scheduler per server/database. The installer writes the same `thetaxi-wialon-scheduler.service` unit on both hosts, validates the PHP version and app path, and enables it. In **Vehicles > Live Tracking**, save each company's token and optionally select report resources, unit groups, or individual units. Report resources only control report templates; unit groups are a bulk way to include devices for sync. Neither is needed to link a device to a vehicle. The fleet list starts with saved portal vehicles, and selected Wialon units without an active portal vehicle appear in a separate pending list. Link one to an existing vehicle or create its vehicle in the portal; the device name and Wialon mileage are prefilled. The scheduler syncs selected units to linked portal vehicles every five minutes. Keep the web runtime on PHP 8.5 as well, because this release's Composer dependencies require PHP 8.5.

The install script updates the existing unit in place, so re-running it does not add a second scheduler.
