<?php

namespace App\Console\Commands;

use App\Services\ClickAnalyticsService;
use Illuminate\Console\Command;

class GenerateShortUrlAnalytics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'short-urls:analytics 
                            {--start-date= : Start date for analytics (YYYY-MM-DD)}
                            {--end-date= : End date for analytics (YYYY-MM-DD)}
                            {--format=table : Output format (table, json)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate analytics report for short URLs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startDate = $this->option('start-date') ?? now()->subDays(7)->toDateString();
        $endDate = $this->option('end-date') ?? now()->toDateString();
        $format = $this->option('format');

        $this->info("Generating analytics report from {$startDate} to {$endDate}...");
        
        $summary = ClickAnalyticsService::getAnalyticsSummary($startDate, $endDate);
        
        if ($format === 'json') {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT));
        } else {
            $this->displayTableFormat($summary);
        }
        
        return 0;
    }

    private function displayTableFormat(array $summary): void
    {
        $this->info('=== Short URL Analytics Summary ===');
        $this->line("Total Clicks: {$summary['total_clicks']}");
        $this->line("Unique IPs: {$summary['unique_ips']}");
        
        if (!empty($summary['countries'])) {
            $this->info("\n=== Top Countries ===");
            $this->table(['Country', 'Clicks'], 
                collect($summary['countries'])->map(fn($count, $country) => [$country, $count])->toArray()
            );
        }
        
        if (!empty($summary['devices'])) {
            $this->info("\n=== Device Types ===");
            $this->table(['Device', 'Clicks'], 
                collect($summary['devices'])->map(fn($count, $device) => [$device, $count])->toArray()
            );
        }
        
        if (!empty($summary['browsers'])) {
            $this->info("\n=== Top Browsers ===");
            $this->table(['Browser', 'Clicks'], 
                collect($summary['browsers'])->take(5)->map(fn($count, $browser) => [$browser, $count])->toArray()
            );
        }
    }
}