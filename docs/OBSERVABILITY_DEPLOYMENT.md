# Observability deployment

The repository code is shared. Copy `deploy/alloy/project.env.example` to
`/etc/alloy/project.env`, set the real absolute `LARAVEL_LOG_DIR`, and leave
only the deployed company block uncommented. The Loki password never belongs
in this repository.

## Production commands

Run these separately on the Ubuntu application server:

```bash
pwd
php artisan --version
stat -c '%U:%G %n' storage storage/logs
systemctl status alloy --no-pager
```

Install Alloy from Grafana's official Debian/Ubuntu repository only if the last
command confirms it is absent. Then install ACL support:

```bash
sudo apt-get update
sudo apt-get install -y alloy acl
```

Install the repository templates and select the company in the env file:

```bash
sudo install -m 0644 deploy/alloy/config.alloy /etc/alloy/config.alloy
sudo install -m 0644 deploy/alloy/project.env.example /etc/alloy/project.env
sudoedit /etc/alloy/project.env
```

Create the password file yourself using the real password. Stop here until the
password is available; do not invent or commit it:

```bash
sudoedit /etc/alloy/loki_password
sudo chown root:alloy /etc/alloy/loki_password
sudo chmod 0640 /etc/alloy/loki_password
```

Make Alloy load the selected deployment variables:

```bash
sudo systemctl edit alloy
```

Add:

```ini
[Service]
EnvironmentFile=/etc/alloy/project.env
```

Grant read-only traversal/read access, replacing the path with the verified
absolute log directory from `project.env`:

```bash
sudo setfacl -R -m u:alloy:rX /REAL/ABSOLUTE/PATH/public-thetaxi/storage/logs
sudo setfacl -R -d -m u:alloy:rX /REAL/ABSOLUTE/PATH/public-thetaxi/storage/logs
```

Apply application and service configuration:

```bash
php artisan optimize:clear
php artisan config:cache
sudo systemctl daemon-reload
sudo systemctl enable alloy
sudo systemctl restart alloy
```

Write identifiable local test events:

```bash
php artisan tinker --execute="Log::channel('observability_backend')->info('OBSERVABILITY_BACKEND_TEST', ['project' => config('app.project_slug')]);"
curl -X POST "https://YOUR-BACKEND/api/observability/frontend-log" -H "Content-Type: application/json" --data '{"level":"error","message":"OBSERVABILITY_FRONTEND_TEST","timestamp":"2026-09-11T00:00:00Z"}'
tail -n 1 storage/logs/observability-backend-$(date +%F).log
tail -n 1 storage/logs/observability-frontend-$(date +%F).log
systemctl status alloy --no-pager
journalctl -u alloy -n 100 --no-pager
```

Build Angular using its existing package script:

```bash
npm run build
```

## Grafana queries

Replace `PROJECT_SLUG` with `casons` or `thetaxi`:

```logql
{project="PROJECT_SLUG",component="backend",environment="production"}
{project="PROJECT_SLUG",component="frontend",environment="production"}
{project="PROJECT_SLUG",environment="production"}
{project="PROJECT_SLUG",environment="production"} |~ "(?i)error|exception|fatal"
```
