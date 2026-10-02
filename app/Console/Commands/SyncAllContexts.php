<?php

namespace App\Console\Commands;

use App\Models\Agent\Agent;
use App\Models\Customer;
use App\Models\Driver\Driver;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
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

                $contextQuery = UserContext::where('user_id', $record->user_id)->where('context_type', $contextType);
                $changed = $dryRun
                    ? ($contextType === 'staff' && $record->employment_ended_at
                        ? (clone $contextQuery)->where('is_active', true)->exists()
                        : !$contextQuery->exists() || $force)
                    : DB::transaction(function () use ($record, $contextType, $force): bool {
                        $user = User::query()->whereKey($record->user_id)->lockForUpdate()->firstOrFail();
                        $context = UserContext::where('user_id', $user->id)
                            ->where('context_type', $contextType)
                            ->lockForUpdate()
                            ->first();

                        if ($contextType === 'staff' && $record->employment_ended_at) {
                            $activeContexts = UserContext::query()
                                ->where('user_id', $user->id)
                                ->where('context_type', 'staff')
                                ->where('is_active', true)
                                ->lockForUpdate()
                                ->get();
                            if ($activeContexts->isEmpty()) {
                                return false;
                            }

                            $actor = User::query()->find(config('hr.system_user_id'));
                            abort_unless($actor, 409, 'HR_SYSTEM_USER_ID must identify an existing system actor.');
                            $deactivated = app(\App\Services\UserContextService::class)->deactivateContext(
                                $user,
                                'staff',
                                null,
                                $actor->id
                            );
                            abort_unless($deactivated, 409, 'Staff contexts changed during synchronization.');
                            activity('user-access')->causedBy($actor)->performedOn($user)
                                ->withProperties([
                                    'context_type' => 'staff',
                                    'staff_id' => $record->id,
                                    'user_context_count' => $activeContexts->count(),
                                    'source_command' => 'contexts:sync-all',
                                ])->log('former_staff_context_deactivated_by_sync');

                            return true;
                        }

                        if ($context && !$force) {
                            return false;
                        }

                        if ($context) {
                            $context->update(['context_id' => $record->id, 'is_active' => $record->is_active ?? true]);
                        } else {
                            $context = UserContext::create([
                                'user_id' => $user->id,
                                'context_type' => $contextType,
                                'context_id' => $record->id,
                                'is_active' => $record->is_active ?? true,
                            ]);

                            $roleName = $this->getRoleForContext($contextType);
                            if ($roleName) {
                                app(\App\Services\UserContextService::class)->assignRolesToContext($user, $context, [$roleName]);
                            }
                        }

                        return true;
                    });

                $changed ? $created++ : $skipped++;

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
