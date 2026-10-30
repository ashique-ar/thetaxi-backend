<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('manage-roles', 'api');

        Role::query()->where('guard_name', 'api')
            ->where('name', 'like', 'Corporate_%_Transport_Coordinator')
            ->get()
            ->filter(fn (Role $role) => preg_match('/^Corporate_[0-9a-f-]{36}_Transport_Coordinator$/i', $role->name) === 1)
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Role managers may have independently changed grants after this migration.
    }
};
