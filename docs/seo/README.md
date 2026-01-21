Sitemap automation and SEO admin settings

What changed:
- Added per-page `canonical_url` support for `InquiryServicePage` (DB migration + API + resource)
- Added backend command: `php artisan sitemap:generate-and-ping` which regenerates sitemap and optionally pings search engines
- Added website settings (admin UI) to control sitemap automation:
  - `sitemap_auto_generate` (bool) - enable/disable automatic sitemap generation
  - `sitemap_auto_ping` (bool) - enable/disable automatic pinging of search engines after generation
  - `sitemap_ping_urls` (string) - newline separated endpoints; use `{sitemap_url}` placeholder in endpoints

Deployment / Cron setup:
- Ensure `php artisan schedule:run` is executed every minute on your server (add to crontab):

  * * * * * cd /path/to/public-thetaxi && php artisan schedule:run >> /dev/null 2>&1

- The scheduled command will run daily by default (configured in `app/Console/Kernel.php`). You can change frequency in the Kernel if needed.

Testing notes:
- The `sitemap:generate-and-ping` command uses the `WebsiteSettingsService` for toggles and the ping endpoints. Default endpoints include Google and Bing ping URLs.
- Unit/feature test `tests/Feature/SeoTest.php` includes tests for canonical_url and command ping behavior (HTTP calls are faked).

Security & Rate-limiting:
- Be conservative with ping endpoints; do not configure high-frequency or abusive endpoints. The command pings configured endpoints once per generate and may be triggered by the scheduler daily.

If you want me to also add Search Console API re-submission using OAuth credentials, I can implement that next (requires service account/credentials).