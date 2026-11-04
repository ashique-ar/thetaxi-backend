# Apache production tuning

This guide applies the recommended Apache, PHP, and Laravel production settings for the TheTaxi backend. It assumes:

- PHP 8.5 running as `mod_php` under Apache 2.4 (prefork MPM)
- The application is deployed to `/var/www/backend`
- PostgreSQL is the database

PHP 8.5 loads OPcache by default. Do not add a `zend_extension` line for it.

Adjust the paths below if your server uses a different PHP version or install location.

## Before you start

- Confirm the application code is up to date (for example, `git pull` in `/var/www/backend`).
- Keep a copy of the current configuration files so you can roll back (see step 4).
- Have `sudo` access on the server.

## Deployment steps

1. **Build the Laravel caches.** Run this from the application directory, as the web server user:

   ```bash
   cd /var/www/backend
   sudo -u www-data php artisan optimize
   ```

   This builds the configuration, event, route, and view caches. Run it again after every code deployment.

2. **Back up the existing configuration files.** Skip any file that does not exist yet.

   ```bash
   sudo cp /etc/php/8.5/apache2/conf.d/10-opcache.ini /etc/php/8.5/apache2/conf.d/10-opcache.ini.bak 2>/dev/null
   sudo cp /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf.bak
   ```

3. **Install the two configuration files.** Set `DEPLOY` to the `deploy/apache` folder in the application:

   ```bash
   DEPLOY=/var/www/backend/deploy/apache
   sudo cp $DEPLOY/php/10-opcache.ini /etc/php/8.5/apache2/conf.d/10-opcache.ini
   sudo cp $DEPLOY/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
   ```

4. **Test the Apache configuration, then reload.**

   ```bash
   sudo /usr/sbin/apache2ctl configtest
   sudo systemctl reload apache2
   ```

   Continue only if `configtest` prints `Syntax OK`. If it reports an error, restore the backups from step 2, run `configtest` again, and then reload.

   You may also see this warning. It is harmless:

   ```text
   AH00558: apache2: Could not reliably determine the server's fully qualified domain name
   ```

   To remove it, add `ServerName <your-domain>` to `/etc/apache2/apache2.conf`.

5. **Verify the result.**

   ```bash
   cd /var/www/backend
   php artisan about --only=cache
   sudo apache2ctl -M | grep mpm
   ```

   - `php artisan about --only=cache` should show Config, Events, Routes, and Views as `CACHED`.
   - `sudo apache2ctl -M | grep mpm` should show `mpm_prefork_module`.

   The PHP command-line settings can differ from the web settings, because the CLI reads its own configuration. To confirm the web settings, use a temporary check file and delete it right away. Replace `your-domain.example` with the domain that serves the application:

   ```bash
   echo '<?php echo "mem=", ini_get("opcache.memory_consumption"), " enable_cli=", ini_get("opcache.enable_cli");' | sudo tee /var/www/backend/public/opcache-check.php
   curl -sk --resolve your-domain.example:443:127.0.0.1 https://your-domain.example/opcache-check.php; echo
   sudo rm /var/www/backend/public/opcache-check.php
   ```

   The expected output is `mem=192 enable_cli=0`.

6. **Monitor under load.** During a representative busy period, compare CPU usage, the number of Apache worker processes (`pgrep -c apache2`), and PostgreSQL connections (`SELECT count(*) FROM pg_stat_activity;`) with your pre-deployment baseline.

## Configuration summary

| File | Setting | Value | Purpose |
| --- | --- | --- | --- |
| `mpm_prefork.conf` | `MaxRequestWorkers` | 48 | Caps concurrent Apache workers so PostgreSQL connections stay within the database limit |
| `mpm_prefork.conf` | `StartServers` / `MinSpareServers` / `MaxSpareServers` | 5 / 5 / 10 | Keeps a small pool of ready workers |
| `mpm_prefork.conf` | `MaxConnectionsPerChild` | 0 | Workers are not recycled after a fixed number of requests |
| `10-opcache.ini` | `opcache.memory_consumption` | 192 | Memory for compiled PHP code |
| `10-opcache.ini` | `opcache.max_accelerated_files` | 20000 | Enough slots for all PHP files in the application |
| `10-opcache.ini` | `opcache.validate_timestamps` / `revalidate_freq` | 1 / 2 | Code changes take effect within about 2 seconds, without a restart |

## Notes

- **Do not raise `MaxRequestWorkers` without testing.** Requests above the worker limit wait in a queue. Raise the limit only after measuring memory use per worker and confirming that the database has spare connections.
- **Re-run `php artisan optimize` after every deployment.** Laravel's caches go out of date when configuration or routes change.

## Rolling back

If the server misbehaves after these changes, restore the backups and reload:

```bash
sudo cp /etc/php/8.5/apache2/conf.d/10-opcache.ini.bak /etc/php/8.5/apache2/conf.d/10-opcache.ini
sudo cp /etc/apache2/mods-available/mpm_prefork.conf.bak /etc/apache2/mods-available/mpm_prefork.conf
sudo /usr/sbin/apache2ctl configtest
sudo systemctl reload apache2
```
