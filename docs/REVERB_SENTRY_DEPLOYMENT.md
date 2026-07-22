# Reverb and Sentry Deployment Guide

This guide explains how to set up Laravel Reverb WebSockets and Sentry error monitoring for each company deployment.

For Booking Management signal definitions, dashboards, alert routing, privacy boundaries, and validation, also follow [BOOKING_OPERATIONS_MONITORING.md](BOOKING_OPERATIONS_MONITORING.md).

Use this document when onboarding a new company, staging domain, or production domain.

## Components

- Backend: Laravel in `public-thetaxi`
- Frontend portal: Angular in `portal-thetaxi`
- WebSocket server: Laravel Reverb
- Error monitoring: Sentry
- Process manager on Bitnami servers: `systemd`
- Web server on Bitnami servers: Apache
- Optional proxy/CDN: Cloudflare

## Values To Prepare Per Company

Prepare these values before deployment:

```text
COMPANY_NAME=
BACKEND_DOMAIN=api-or-backend-domain.example.com
PORTAL_DOMAIN=portal-domain.example.com
APP_ENV=production
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
SENTRY_BACKEND_DSN=
SENTRY_FRONTEND_DSN=
```

Generate Reverb values with PHP:

```bash
php -r "echo bin2hex(random_bytes(16)).PHP_EOL;"
php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"
```

Recommended mapping:

```env
REVERB_APP_ID=company-production
REVERB_APP_KEY=<32_char_random_value>
REVERB_APP_SECRET=<64_char_random_value>
```

Only `REVERB_APP_KEY` is exposed to Angular. Never expose `REVERB_APP_SECRET` in frontend files.

## Backend Environment

Edit the Laravel `.env` file on the server:

```bash
cd /home/bitnami/htdocs/<backend-folder>
nano .env
```

Use exactly one `BROADCAST_CONNECTION` entry:

```env
BROADCAST_CONNECTION=reverb
```

Add or update:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://BACKEND_DOMAIN

BROADCAST_CONNECTION=reverb

REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_APP_ID=company-production
REVERB_APP_KEY=<generated_app_key>
REVERB_APP_SECRET=<generated_app_secret>
REVERB_HOST=BACKEND_DOMAIN
REVERB_PORT=443
REVERB_SCHEME=https

SENTRY_LARAVEL_DSN=<sentry_backend_dsn>
SENTRY_ENVIRONMENT=production
SENTRY_TRACES_SAMPLE_RATE=0.05
SENTRY_PROFILES_SAMPLE_RATE=0
SENTRY_SEND_DEFAULT_PII=false
```

Then clear and cache config:

```bash
/opt/bitnami/php/bin/php artisan config:clear
/opt/bitnami/php/bin/php artisan config:cache
```

## Install Backend Dependencies

From the Laravel backend folder:

```bash
cd /home/bitnami/htdocs/<backend-folder>
composer install --no-dev --optimize-autoloader
```

If Reverb is not installed yet:

```bash
composer require laravel/reverb
/opt/bitnami/php/bin/php artisan reverb:install --no-interaction
```

If Sentry is not installed yet:

```bash
composer require sentry/sentry-laravel
/opt/bitnami/php/bin/php artisan sentry:publish --no-interaction
```

This project already wires Sentry exception handling in `bootstrap/app.php`.

## Frontend Environment

Edit the Angular production environment:

```text
portal-thetaxi/src/environment/environment.prod.ts
```

Set:

```ts
export const environment = {
  production: true,
  apiPrefix: 'api/',
  apiUrl: 'https://BACKEND_DOMAIN/',
  media_url: 'https://CDN_OR_BACKEND_MEDIA_DOMAIN/',
  googleMapsApiKey: '<google_maps_key>',
  seoIndexable: true,
  sentryDsn: '<sentry_frontend_dsn>',
  reverbHost: 'BACKEND_DOMAIN',
  reverbPort: 443,
  reverbHttps: true,
  reverbAppKey: '<same_value_as_REVERB_APP_KEY>',
};
```

Important:

- `reverbHost` must be only the host, for example `dev.casons.lk`.
- Do not include `https://` in `reverbHost`.
- `reverbAppKey` must equal backend `REVERB_APP_KEY`.
- Do not put `REVERB_APP_SECRET` in Angular.

Build the portal:

```bash
cd /path/to/portal-thetaxi
npm install
npm run build
```

Deploy the built Angular files to the company portal document root.

## Run Reverb With Systemd

Check server process manager:

```bash
ps -p 1 -o comm=
```

If it returns `systemd`, create a systemd service:

```bash
sudo nano /etc/systemd/system/company-reverb.service
```

Use:

```ini
[Unit]
Description=Company Laravel Reverb WebSocket Server
After=network.target bitnami.service
Requires=bitnami.service

[Service]
Type=simple
User=bitnami
Group=bitnami
WorkingDirectory=/home/bitnami/htdocs/<backend-folder>
ExecStart=/opt/bitnami/php/bin/php artisan reverb:start --host=0.0.0.0 --port=8080
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable company-reverb
sudo systemctl start company-reverb
sudo systemctl status company-reverb --no-pager
```

Useful maintenance commands:

```bash
sudo systemctl restart company-reverb
sudo journalctl -u company-reverb -n 100 --no-pager
```

## Apache Proxy For Reverb

On Bitnami Apache, enable the WebSocket proxy module if needed:

```bash
/opt/bitnami/apache/bin/httpd -M | grep -E "proxy_wstunnel|proxy|rewrite|ssl"
```

If `proxy_wstunnel_module` is missing, check:

```bash
grep -R "proxy_wstunnel" /opt/bitnami/apache/conf
```

Edit Apache config:

```bash
sudo nano /opt/bitnami/apache/conf/httpd.conf
```

Uncomment:

```apache
LoadModule proxy_wstunnel_module modules/mod_proxy_wstunnel.so
```

Find the vhost file for the company backend domain:

```bash
grep -R "BACKEND_DOMAIN\|DocumentRoot\|VirtualHost" /opt/bitnami/apache/conf/vhosts /opt/bitnami/apache/conf/bitnami 2>/dev/null
```

Back up the vhost:

```bash
sudo cp /opt/bitnami/apache/conf/vhosts/<vhost-file>.conf /opt/bitnami/apache/conf/vhosts/<vhost-file>.conf.backup.$(date +%Y%m%d%H%M%S)
```

Edit the vhost:

```bash
sudo nano /opt/bitnami/apache/conf/vhosts/<vhost-file>.conf
```

Inside the `<VirtualHost *:443>` block, before `</VirtualHost>`, add:

```apache
ProxyPreserveHost On

ProxyPass "/app" "ws://127.0.0.1:8080/app"
ProxyPassReverse "/app" "ws://127.0.0.1:8080/app"

ProxyPass "/apps" "http://127.0.0.1:8080/apps"
ProxyPassReverse "/apps" "http://127.0.0.1:8080/apps"
```

Test and restart Apache:

```bash
sudo /opt/bitnami/apache/bin/apachectl configtest
sudo /opt/bitnami/ctlscript.sh restart apache
```

## Cloudflare Setup

If the domain uses Cloudflare, enable WebSockets:

```text
Cloudflare dashboard -> Domain -> Network -> WebSockets -> On
```

Create cache bypass rules.

Rule for `/app/*`:

```text
Rule name: Bypass Reverb WebSocket app
Expression:
(http.host eq "BACKEND_DOMAIN" and starts_with(http.request.uri.path, "/app/"))
Action:
Cache eligibility: Bypass cache
```

Rule for `/apps/*`:

```text
Rule name: Bypass Reverb WebSocket apps
Expression:
(http.host eq "BACKEND_DOMAIN" and starts_with(http.request.uri.path, "/apps/"))
Action:
Cache eligibility: Bypass cache
```

If WebSocket requests still fail, create a temporary WAF skip rule:

```text
Rule name: Skip WAF for Reverb
Expression:
(http.host eq "BACKEND_DOMAIN" and (starts_with(http.request.uri.path, "/app/") or starts_with(http.request.uri.path, "/apps/")))
Action:
Skip WAF managed rules, bot checks, and browser integrity checks for testing
```

After verification, narrow the skip rule to only what is required.

## Verification

Check Sentry backend:

```bash
cd /home/bitnami/htdocs/<backend-folder>
/opt/bitnami/php/bin/php artisan config:clear
/opt/bitnami/php/bin/php artisan sentry:test
```

Expected:

```text
DSN discovered from Laravel config or `.env` file!
Sending test event...
Test event sent with ID: ...
```

Check Reverb service:

```bash
sudo systemctl status company-reverb --no-pager
```

Expected:

```text
Active: active (running)
```

Check direct Reverb handshake:

```bash
curl -i \
  --http1.1 \
  -H "Host: BACKEND_DOMAIN" \
  -H "Connection: Upgrade" \
  -H "Upgrade: websocket" \
  -H "Sec-WebSocket-Version: 13" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  "http://127.0.0.1:8080/app/REVERB_APP_KEY?protocol=7&client=js&version=8.4.0&flash=false"
```

Expected:

```text
HTTP/1.1 101 Switching Protocols
X-Powered-By: Laravel Reverb
```

Check Apache without Cloudflare:

```bash
curl -k -i \
  --http1.1 \
  --resolve BACKEND_DOMAIN:443:127.0.0.1 \
  -H "Connection: Upgrade" \
  -H "Upgrade: websocket" \
  -H "Sec-WebSocket-Version: 13" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  "https://BACKEND_DOMAIN/app/REVERB_APP_KEY?protocol=7&client=js&version=8.4.0&flash=false"
```

Expected:

```text
HTTP/1.1 101 Switching Protocols
X-Powered-By: Laravel Reverb
```

Check public route through Cloudflare:

```bash
curl -i \
  --http1.1 \
  -H "Connection: Upgrade" \
  -H "Upgrade: websocket" \
  -H "Sec-WebSocket-Version: 13" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  "https://BACKEND_DOMAIN/app/REVERB_APP_KEY?protocol=7&client=js&version=8.4.0&flash=false"
```

Expected:

```text
HTTP/1.1 101 Switching Protocols
X-Powered-By: Laravel Reverb
```

## Troubleshooting

Duplicate env key:

```env
BROADCAST_CONNECTION=log
BROADCAST_CONNECTION=reverb
```

Fix by keeping only:

```env
BROADCAST_CONNECTION=reverb
```

Angular connection fails:

- Confirm `reverbHost` has no protocol.
- Confirm `reverbPort` is `443` for HTTPS proxy.
- Confirm `reverbHttps` is `true`.
- Confirm `reverbAppKey` matches backend `REVERB_APP_KEY`.
- Rebuild and redeploy Angular after changes.

Public WebSocket gives Cloudflare `500`, but local Apache test gives `101`:

- Cloudflare is the issue.
- Enable WebSockets.
- Bypass cache for `/app/*` and `/apps/*`.
- Check WAF or bot rules.

Apache test fails:

- Confirm proxy rules are inside `<VirtualHost *:443>`.
- Confirm `proxy_wstunnel_module` is loaded.
- Restart Apache after config changes.

Direct Reverb test fails:

- Check service status:

```bash
sudo systemctl status company-reverb --no-pager
```

- Check service logs:

```bash
sudo journalctl -u company-reverb -n 100 --no-pager
```

- Restart after `.env` changes:

```bash
/opt/bitnami/php/bin/php artisan config:clear
/opt/bitnami/php/bin/php artisan config:cache
sudo systemctl restart company-reverb
```

Sentry does not receive events:

- Confirm `SENTRY_LARAVEL_DSN` is set.
- Run `php artisan sentry:test`.
- Confirm the Sentry project is for Laravel/backend.
- Confirm outbound HTTPS is allowed from the server.

## Per-Company Checklist

Use this checklist for every company:

```text
[ ] Backend domain chosen
[ ] Portal domain chosen
[ ] Reverb app id/key/secret generated
[ ] Backend .env updated
[ ] Angular environment.prod.ts updated
[ ] composer install completed
[ ] Laravel config cleared and cached
[ ] Reverb systemd service created
[ ] Reverb service active
[ ] Apache proxy module enabled
[ ] Apache HTTPS vhost proxies /app and /apps
[ ] Apache configtest passes
[ ] Apache restarted
[ ] Cloudflare WebSockets enabled
[ ] Cloudflare cache bypass rules created
[ ] Direct Reverb handshake returns 101
[ ] Apache local handshake returns 101
[ ] Public handshake returns 101
[ ] Sentry backend test event received
[ ] Angular build deployed
[ ] Browser console has no WebSocket or Sentry init errors
```
