<?php

namespace App\Console\Commands;

use App\Services\Driver\SessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Process Auto-Offline Command
 * 
 * Scheduled command that automatically marks inactive drivers as offline.
 * Runs every 5 minutes to check for drivers whose last_active_at exceeds
 * the configured timeout threshold.
 * 
 * @see Requirement 5.3 - Scheduled job every 5 minutes
 * @see Requirement 5.4 - Auto-close sessions on timeout
 * @see Requirement 5.5 - Set is_online to false on auto-offline
 */
class ProcessAutoOfflineCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'drivers:process-auto-offline';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process auto-offline for inactive drivers whose last activity exceeds the timeout threshold';

    /**
     * The session service instance.
     *
     * @var SessionService
     */
    protected SessionService $sessionService;

    /**
     * Create a new command instance.
     *
     * @param SessionService $sessionService
     */
    public function __construct(SessionService $sessionService)
    {
        parent::__construct();
        $this->sessionService = $sessionService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Processing auto-offline for inactive drivers...');

        try {
            $count = $this->sessionService->processAutoOffline();

            if ($count > 0) {
                $message = "Auto-offline processed: {$count} driver(s) marked offline due to inactivity.";
                $this->info($message);
                Log::info($message);
            } else {
                $this->info('No inactive drivers found to process.');
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = 'Failed to process auto-offline: ' . $e->getMessage();
            $this->error($errorMessage);
            Log::error($errorMessage, [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }
}
