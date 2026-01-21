<?php

namespace App\Console\Commands;

use App\Services\PerformanceOptimizationService;
use App\Services\WebsiteSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateSitemapAndPing extends Command
{
    protected $signature = 'sitemap:generate-and-ping';

    protected $description = 'Generate sitemap and optionally ping search engines using configured endpoints';

    protected PerformanceOptimizationService $perf;
    protected WebsiteSettingsService $settings;

    public function __construct(PerformanceOptimizationService $perf, WebsiteSettingsService $settings)
    {
        parent::__construct();

        $this->perf = $perf;
        $this->settings = $settings;
    }

    public function handle(): int
    {
        $auto = filter_var($this->settings->get('sitemap_auto_generate', true), FILTER_VALIDATE_BOOLEAN);

        if (!$auto) {
            $this->info('Sitemap auto-generation disabled in settings.');
            return 0;
        }

        $this->info('Generating sitemap...');

        try {
            $xml = $this->perf->generateSitemap();
            // generateSitemap caches the sitemap. We still show size for info
            $this->info('Sitemap generated (' . strlen($xml) . ' bytes)');
        } catch (\Throwable $e) {
            Log::error('Sitemap generation failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->error('Sitemap generation failed. See logs for details.');
            return 1;
        }

        $autoPing = filter_var($this->settings->get('sitemap_auto_ping', true), FILTER_VALIDATE_BOOLEAN);
        if (!$autoPing) {
            $this->info('Sitemap auto-ping disabled in settings.');
            return 0;
        }

        // Use secure + www-prefixed default endpoints (safer against redirects and preferred by engines)
        $endpointsRaw = $this->settings->get('sitemap_ping_urls', "https://www.google.com/ping?sitemap={sitemap_url}\nhttps://www.bing.com/webmaster/ping.aspx?siteMap={sitemap_url}");

        $endpoints = preg_split('/\r?\n/', trim((string) $endpointsRaw)) ?: [];

        $sitemapUrl = url(route('sitemap', [], false));

        foreach ($endpoints as $endpoint) {
            $endpoint = trim($endpoint);
            if (empty($endpoint)) {
                continue;
            }

            $urlToCall = str_replace('{sitemap_url}', urlencode($sitemapUrl), $endpoint);

            $this->info("Pinging: {$urlToCall}");

            try {
                $response = Http::get($urlToCall);
                $status = $response->status();
                $this->info("Ping response: {$status} for {$urlToCall}");

                // Treat common successful status codes as success
                if (in_array($status, [200, 201, 202, 204], true)) {
                    continue;
                }

                // If 404/410 (or other non-success) received, attempt fallbacks
                if (in_array($status, [404, 410], true) || $status >= 400) {
                    Log::warning('Sitemap ping returned ' . $status . ' for endpoint: ' . $urlToCall);

                    // Attempt alternate host / scheme / encoding strategies
                    $attempts = [];
                    $parsed = @parse_url($endpoint);

                    if ($parsed !== false && !empty($parsed['host'])) {
                        $host = $parsed['host'];
                        $hostWithWww = strpos($host, 'www.') === 0 ? $host : ('www.' . $host);

                        if ($hostWithWww !== $host) {
                            $alternate = str_replace($host, $hostWithWww, $endpoint);
                            $attempts[] = str_replace('{sitemap_url}', $sitemapUrl, $alternate); // raw
                            $attempts[] = str_replace('{sitemap_url}', urlencode($sitemapUrl), $alternate); // encoded
                        }

                        // Try forcing https if not already
                        if (($parsed['scheme'] ?? '') !== 'https') {
                            $httpsAlt = preg_replace('#^http:#', 'https:', $endpoint);
                            $attempts[] = str_replace('{sitemap_url}', urlencode($sitemapUrl), $httpsAlt);
                            $attempts[] = str_replace('{sitemap_url}', $sitemapUrl, $httpsAlt);
                        }
                    }

                    // As a last resort: try endpoint with raw/unencoded sitemap if we used encoded value
                    $attempts[] = str_replace('{sitemap_url}', $sitemapUrl, $endpoint);

                    $attempts = array_values(array_unique(array_filter($attempts)));

                    $succeeded = false;
                    foreach ($attempts as $altUrl) {
                        $this->info("Retrying ping with: {$altUrl}");
                        try {
                            $altResp = Http::get($altUrl);
                            $this->info("Alternate ping response: {$altResp->status()} for {$altUrl}");

                            if (in_array($altResp->status(), [200, 201, 202, 204], true)) {
                                $this->info("Ping successful via alternate endpoint: {$altUrl}");
                                $succeeded = true;
                                break;
                            }
                        } catch (\Throwable $e) {
                            Log::warning('Alternate sitemap ping failed for: ' . $altUrl, ['exception' => $e]);
                        }
                    }

                    if (!$succeeded) {
                        Log::warning('All ping attempts failed for original endpoint: ' . $endpoint, ['original_url' => $urlToCall, 'status' => $status]);
                        $this->error("All ping attempts failed for {$urlToCall} (status: {$status})");
                    }
                }

            } catch (\Throwable $e) {
                Log::warning('Sitemap ping failed for endpoint: ' . $endpoint, ['exception' => $e]);
                $this->error("Ping failed for {$urlToCall}: " . $e->getMessage());
            }
        }

        return 0;
    }
}
