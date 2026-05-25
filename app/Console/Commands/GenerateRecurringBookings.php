<?php

namespace App\Console\Commands;

use App\Services\RecurringBookingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateRecurringBookings extends Command
{
    protected $signature   = 'bookings:generate-recurring {--dry-run : Show what would be created without saving}';
    protected $description = 'Generate upcoming occurrences for all active recurring bookings';

    public function handle(RecurringBookingService $service): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('[DRY RUN] No changes will be saved.');
        }

        $this->info('Generating recurring booking occurrences…');

        try {
            $summary = $service->generateAllDue();

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Templates processed', $summary['processed']],
                    ['Occurrences created', $summary['created']],
                    ['Errors',              $summary['errors']],
                ]
            );

            if ($summary['errors'] > 0) {
                $this->warn('Some templates failed — check the application log for details.');
                return self::FAILURE;
            }

            $this->info('Done.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Fatal error: ' . $e->getMessage());
            Log::error('GenerateRecurringBookings command failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }
    }
}
