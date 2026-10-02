<?php

namespace App\Console\Commands;

use App\Models\Driver\Driver;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncDriverContexts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contexts:sync-drivers 
                            {--dry-run : Run without making changes}
                            {--force : Force sync even if context exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync existing drivers to user contexts';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('Starting driver context sync...');
        $this->info($dryRun ? '(DRY RUN MODE - No changes will be made)' : '');
        $this->newLine();

        // Get all drivers with their users
        $drivers = Driver::with('user')->get();
        
        $this->info("Found {$drivers->count()} drivers");
        $this->newLine();

        $created = 0;
        $skipped = 0;
        $errors = 0;

        $progressBar = $this->output->createProgressBar($drivers->count());
        $progressBar->start();

        foreach ($drivers as $driver) {
            try {
                // Check if user exists
                if (!$driver->user) {
                    $this->newLine();
                    $this->warn("Driver {$driver->id} has no associated user. Skipping.");
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }

                $changed = $dryRun
                    ? !UserContext::where('user_id', $driver->user_id)->where('context_type', 'driver')->exists() || $force
                    : DB::transaction(function () use ($driver, $force): bool {
                        $user = User::query()->whereKey($driver->user_id)->lockForUpdate()->firstOrFail();
                        $context = UserContext::where('user_id', $user->id)
                            ->where('context_type', 'driver')
                            ->lockForUpdate()
                            ->first();

                        if ($context && !$force) {
                            return false;
                        }

                        if ($context) {
                            $context->update(['context_id' => $driver->id, 'is_active' => $driver->is_active ?? true]);
                        } else {
                            $context = UserContext::create([
                                'user_id' => $user->id,
                                'context_type' => 'driver',
                                'context_id' => $driver->id,
                                'is_active' => $driver->is_active ?? true,
                            ]);
                            app(\App\Services\UserContextService::class)->assignRolesToContext($user, $context, ['driver']);
                        }

                        return true;
                    });

                $changed ? $created++ : $skipped++;

            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Error processing driver {$driver->id}: {$e->getMessage()}");
                $errors++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Sync completed!');
        $this->table(
            ['Status', 'Count'],
            [
                ['Created/Updated', $created],
                ['Skipped', $skipped],
                ['Errors', $errors],
                ['Total', $drivers->count()],
            ]
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn('This was a DRY RUN. No changes were made.');
            $this->info('Run without --dry-run to apply changes.');
        }

        return Command::SUCCESS;
    }
}
