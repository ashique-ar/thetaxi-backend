# GPS fleet scheduler

Casons and TheTaxi run on separate servers. Install the same scheduler on each server, from that server's application directory. Each installation reads the enabled companies' encrypted GPS settings from its own database; no GPS token is stored in an environment file.

## PHP runtime requirement

This release requires PHP 8.5 for both the web application and scheduled commands. The Casons server previously reported PHP 8.3.22 from `/opt/bitnami/php/bin/php`, so it cannot run this release with that binary.

Bitnami stacks include their own PHP runtime. Installing a second system PHP does not upgrade the PHP used by the Bitnami web stack; update or migrate the stack using its supported procedure, then verify the web app and CLI both run PHP 8.5. See [Bitnami's stack migration guidance](https://docs.bitnami.com/vmware-marketplace/how-to/migrate-moodle/) and [PHP's supported versions](https://www.php.net/supported-versions.php).

After the server runtime is updated, verify the CLI binary:

```bash
php_bin="$(type -P php)"
"$php_bin" -v
```

## Install on each server

The installer validates PHP 8.5 and checks that Laravel boots as the service user before writing the unit. It uses one generic unit name on both independent servers and disables the earlier fleet scheduler unit during upgrade.

```bash
# Casons server
cd /home/bitnami/htdocs/casons-public
app_dir="$(pwd -P)"
php_bin="$(type -P php)"
sudo bash deploy/systemd/install-gps-scheduler.sh "$app_dir" "$php_bin" bitnami

# TheTaxi server: run these on its separate server
cd /home/bitnami/htdocs/thetaxi-public
app_dir="$(pwd -P)"
php_bin="$(type -P php)"
sudo bash deploy/systemd/install-gps-scheduler.sh "$app_dir" "$php_bin" bitnami
```

Before deploying a release with database changes, run migrations with that same PHP binary:

```bash
sudo -u bitnami "$php_bin" artisan migrate --force
```

Check health and recent logs on each server:

```bash
sudo systemctl status gps-fleet-scheduler.service --no-pager
sudo journalctl -u gps-fleet-scheduler.service -n 50 --no-pager
```

The scheduler runs every five minutes. Configure each company's token and selected GPS groups in portal settings; unit groups define fleet scope, while optional report resources only control available report templates.
