# Wialon fleet sync

The web image does not run scheduled tasks. Run one scheduler process from the same release. For Docker deployments, build and run the scheduler stage:

```sh
docker build --target scheduler -t thetaxi-scheduler:<release> .
docker run --env-file <application-env-file> --network <application-network> thetaxi-scheduler:<release>
```

Give it the same database, cache, and application key settings as PHP-FPM. Apply the Laravel migrations, then configure the company token, report resources, Wialon vehicle groups, and their TheTaxi vehicle-group mappings in **Vehicles > Live Tracking**. The scheduler runs `wialon:sync-fleet` every five minutes. Keep one scheduler replica for this app deployment.

For a systemd host, copy `deploy/systemd/thetaxi-wialon-scheduler.service.example` into the release, replace `__DEPLOYMENT_DIRECTORY__`, `__PROCESS_USER__`, `__PROCESS_GROUP__`, and `__PHP_BINARY__`, then install and start it:

```sh
sudo install -m 0644 deploy/systemd/thetaxi-wialon-scheduler.service.example /etc/systemd/system/thetaxi-wialon-scheduler.service
sudo systemctl daemon-reload
sudo systemctl enable --now thetaxi-wialon-scheduler
sudo systemctl status thetaxi-wialon-scheduler
journalctl -u thetaxi-wialon-scheduler -f
```

Run `php artisan migrate --force` from the release before starting the scheduler. Laravel reads the existing app `.env` from its working directory; the Wialon token remains in the database. Run one scheduler instance per deployment.
