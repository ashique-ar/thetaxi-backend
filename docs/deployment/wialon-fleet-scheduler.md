# Wialon fleet sync

Casons and TheTaxi are deployed on separate servers. Deploy the same backend and portal release to both. Each server needs its own PHP 8.5 CLI scheduler running from that server's app directory, so Laravel uses that installation's `.env` and database. Wialon tokens and resource/group selections are stored per company in the database; no Wialon token belongs in `.env`.

The Bitnami PHP binary reported on the Casons server is PHP 8.3.22, while this release requires PHP 8.5. On each Ubuntu server, install PHP 8.5 CLI and the extensions used by the app. This installs a separate CLI version and leaves Bitnami's PHP binary unchanged:

```sh
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.5-cli php8.5-curl php8.5-xml php8.5-mysql php8.5-pgsql php8.5-mbstring php8.5-gd php8.5-bcmath php8.5-zip php8.5-redis
/usr/bin/php8.5 -v
```

Run the following on each server. Use `/home/bitnami/htdocs/casons-public` on Casons or `/home/bitnami/htdocs/thetaxi-public` on TheTaxi. Confirm the path and PHP binary on that host; the installer refuses PHP below 8.5 and validates that Laravel boots as the service user.

```sh
app_dir=/home/bitnami/htdocs/casons-public # use /home/bitnami/htdocs/thetaxi-public on TheTaxi
cd "$app_dir"
php_bin=/usr/bin/php8.5
test -f artisan && "$php_bin" artisan about

"$php_bin" artisan migrate --force

sudo bash deploy/systemd/install-wialon-scheduler.sh "$app_dir" "$php_bin" bitnami
```

Use one scheduler per server/database. The installer writes the same `thetaxi-wialon-scheduler.service` unit on both hosts, validates the PHP version and app path, and enables it. In **Vehicles > Wialon Fleet Tracking**, save the company's token, select the Wialon unit groups that define the fleet scope, and optionally select report resources for report templates. Resources do not control which vehicles sync. There is no individual-unit scope selector. For a newly installed device, an administrator can search for the unit under a selected group and add it from the portal; this requires the Wialon token to have permission to update that group. The unit then appears as pending until the user links it to an existing portal vehicle or creates the portal vehicle in the portal. The device name and Wialon mileage are prefilled during creation. The scheduler syncs selected-group units to linked portal vehicles every five minutes. Keep the web runtime on PHP 8.5 as well, because this release's Composer dependencies require PHP 8.5.

The install script updates the existing unit in place, so re-running it does not add a second scheduler.
