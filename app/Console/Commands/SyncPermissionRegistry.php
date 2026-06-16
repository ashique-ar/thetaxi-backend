<?php

namespace App\Console\Commands;

use App\Services\PermissionRegistry;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;

class SyncPermissionRegistry extends Command
{
    protected $signature = 'permissions:sync-registry {--dry-run : Show what would be created without writing}';

    protected $description = 'Sync canonical permission rows from the centralized permission registry and current database names';

    public function handle(PermissionRegistry $registry): int
    {
        $permissions = $registry->permissions()->pluck('name')->unique()->values();

        if ($this->option('dry-run')) {
            $this->info("Canonical guard: {$registry->canonicalGuard()}");
            $this->info("Permissions available for sync: {$permissions->count()}");
            $permissions->each(fn ($permission) => $this->line($permission));
            return self::SUCCESS;
        }

        $createdOrFound = $registry->ensureCanonicalPermissions($permissions->all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info("Synced {$createdOrFound->count()} permissions to canonical guard [{$registry->canonicalGuard()}].");

        return self::SUCCESS;
    }
}
