# Apache production tuning

These settings target the current PHP 8.5 `mod_php` + prefork host. PHP 8.5 already loads OPcache; do not add a `zend_extension` line.

After deploying the application code:

1. Run `sudo -u www-data php artisan optimize` from `/var/www/backend` to build the missing config, event, route, and view caches.
2. Install `php/10-opcache.ini` as `/etc/php/8.5/apache2/conf.d/10-opcache.ini` and `mpm_prefork.conf` as `/etc/apache2/mods-available/mpm_prefork.conf`.
3. Run `sudo /usr/sbin/apache2ctl configtest`, then `sudo systemctl reload apache2`.
4. Confirm `php artisan about --only=cache` reports the caches enabled, then compare CPU, worker count, and PostgreSQL connections during a representative busy period.

The 48-worker limit leaves headroom below PostgreSQL's observed 81-connection cap. Requests above the Apache worker limit queue; raise it only after measuring worker memory and database connection headroom.
