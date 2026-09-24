<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['api', 'web'] as $guard) {
            $permission = Permission::firstOrCreate([
                'name' => 'drivers.onboarding-drafts.view',
                'guard_name' => $guard,
            ]);

            Role::query()
                ->where('guard_name', $guard)
                ->whereIn('name', ['admin', 'sub-admin'])
                ->each(fn (Role $role) => $role->givePermissionTo($permission));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'drivers.onboarding-drafts.view')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
