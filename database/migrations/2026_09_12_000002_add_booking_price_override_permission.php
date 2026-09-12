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
            $permission = Permission::firstOrCreate(['name' => 'bookings.price_override', 'guard_name' => $guard]);
            Role::query()
                ->where('guard_name', $guard)
                ->whereIn('name', ['admin', 'sub-admin', 'management', 'call center'])
                ->each(fn (Role $role) => $role->givePermissionTo($permission));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'bookings.price_override')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
