<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = collect([
            'drivers.onboarding.view',
            'drivers.onboarding.manage',
            'drivers.onboarding-drafts.view',
        ])->map(fn (string $name) => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'api',
        ]));

        foreach (['admin', 'super-admin', 'fleet-manager', 'driver-coordinator'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'api'])
                ->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Keep permission grants intact: they may also have been assigned manually.
    }
};
