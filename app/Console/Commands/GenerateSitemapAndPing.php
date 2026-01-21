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

        $endpointsRaw = $this->settings->get('sitemap_ping_urls', "http://www.google.com/ping?sitemap={sitemap_url}\nhttps://bing.com/webmaster/ping.aspx?siteMap={sitemap_url}");

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
                $this->info("Ping response: {$response->status()} for {$urlToCall}");
            } catch (\Throwable $e) {
                Log::warning('Sitemap ping failed for endpoint: ' . $endpoint, ['exception' => $e]);
                $this->error("Ping failed for {$urlToCall}: " . $e->getMessage());
            }
        }

        return 0;
    }
}
