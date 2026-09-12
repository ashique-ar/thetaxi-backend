<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const INTERNAL_ROLES = ['admin', 'sub-admin', 'management', 'call center'];

    public function up(): void
    {
        foreach (Permission::query()->where('name', 'bookings.price_override')->get() as $permission) {
            Role::query()
                ->where('guard_name', $permission->guard_name)
                ->whereNotIn('name', self::INTERNAL_ROLES)
                ->each(fn (Role $role) => $role->revokePermissionTo($permission));

            Role::query()
                ->where('guard_name', $permission->guard_name)
                ->whereIn('name', self::INTERNAL_ROLES)
                ->each(fn (Role $role) => $role->givePermissionTo($permission));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Deliberately additive: rollback must not grant sensitive pricing access.
    }
};
