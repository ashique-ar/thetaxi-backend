<?php

namespace App\Console\Commands;

use App\Services\UrlShortenerService;
use Illuminate\Console\Command;

class CleanupExpiredShortUrls extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'short-urls:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired short URLs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Cleaning up expired short URLs...');
        
        $deleted = UrlShortenerService::cleanupExpired();
        
        $this->info("Cleaned up {$deleted} expired short URLs.");
        
        return 0;
    }
}