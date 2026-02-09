<?php

namespace App\Console\Commands;

use App\Models\Agent\Agent;
use App\Models\Customer;
use App\Models\Driver\Driver;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Console\Command;

class SyncAllContexts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contexts:sync-all 
                            {--dry-run : Run without making changes}
                            {--force : Force sync even if context exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync all existing records (drivers, customers, staff, agents) to user contexts';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('Starting full context sync...');
        $this->info($dryRun ? '(DRY RUN MODE - No changes will be made)' : '');
        $this->newLine();

        $totalCreated = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        // Sync Drivers
        $this->info('=== Syncing Drivers ===');
        $result = $this->syncContextType(Driver::class, 'driver', $dryRun, $force);
        $totalCreated += $result['created'];
        $totalSkipped += $result['skipped'];
        $totalErrors += $result['errors'];
        $this->newLine();

        // Sync Customers
        $this->info('=== Syncing Customers ===');
        $result = $this->syncContextType(Customer::class, 'customer', $dryRun, $force);
        $totalCreated += $result['created'];
        $totalSkipped += $result['skipped'];
        $totalErrors += $result['errors'];
        $this->newLine();

        // Sync Staff
        $this->info('=== Syncing Staff ===');
        $result = $this->syncContextType(Staff::class, 'staff', $dryRun, $force);
        $totalCreated += $result['created'];
        $totalSkipped += $result['skipped'];
        $totalErrors += $result['errors'];
        $this->newLine();

        // Sync Agents
        $this->info('=== Syncing Agents ===');
        $result = $this->syncContextType(Agent::class, 'agent', $dryRun, $force);
        $totalCreated += $result['created'];
        $totalSkipped += $result['skipped'];
        $totalErrors += $result['errors'];
        $this->newLine();

        // Overall Summary
        $this->info('=== Overall Summary ===');
        $this->table(
            ['Status', 'Count'],
            [
                ['Created/Updated', $totalCreated],
                ['Skipped', $totalSkipped],
                ['Errors', $totalErrors],
            ]
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn('This was a DRY RUN. No changes were made.');
            $this->info('Run without --dry-run to apply changes.');
        }

        return Command::SUCCESS;
    }

    /**
     * Sync a specific context type
     */
    private function syncContextType(string $modelClass, string $contextType, bool $dryRun, bool $force): array
    {
        $created = 0;
        $skipped = 0;
        $errors = 0;

        $records = $modelClass::with('user')->get();
        $this->info("Found {$records->count()} {$contextType}s");

        $progressBar = $this->output->createProgressBar($records->count());
        $progressBar->start();

        foreach ($records as $record) {
            try {
                // Check if user exists
                if (!$record->user) {
                    $this->newLine();
                    $this->warn("{$contextType} {$record->id} has no associated user. Skipping.");
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }

                // Check if context already exists
                $existingContext = UserContext::where('user_id', $record->user_id)
                    ->where('context_type', $contextType)
                    ->first();

                if ($existingContext && !$force) {
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }

                if ($existingContext && $force) {
                    if (!$dryRun) {
                        $existingContext->update([
                            'context_id' => $record->id,
                            'is_active' => $record->is_active ?? true,
                        ]);
                    }
                    $created++;
                } else {
                    // Create new context
                    if (!$dryRun) {
                        UserContext::create([
                            'user_id' => $record->user_id,
                            'context_type' => $contextType,
                            'context_id' => $record->id,
                            'is_active' => $record->is_active ?? true,
                        ]);

                        // Assign corresponding role if not already assigned
                        $roleName = $this->getRoleForContext($contextType);
                        if ($roleName && !$record->user->hasRole($roleName)) {
                            $record->user->assignRole($roleName);
                        }
                    }
                    $created++;
                }

            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Error processing {$contextType} {$record->id}: {$e->getMessage()}");
                $errors++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->table(
            ['Status', 'Count'],
            [
                ['Created/Updated', $created],
                ['Skipped', $skipped],
                ['Errors', $errors],
                ['Total', $records->count()],
            ]
        );

        return [
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Get the role name for a given context type
     */
    private function getRoleForContext(string $contextType): ?string
    {
        $roleMap = [
            'driver' => 'driver',
            'customer' => 'customer',
            'agent' => 'agent',
            'staff' => 'staff',
            'vehicle_owner' => 'vehicle-owner',  // Note: role uses dash
        ];

        return $roleMap[$contextType] ?? null;
    }
}
