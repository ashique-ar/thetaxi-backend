<?php

use App\Services\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $guard = config('permissions.canonical_guard', 'api');
            $permissions = Permission::query()->where('guard_name', $guard)->get();
            $adminPermissions = $permissions
                ->reject(fn (Permission $permission) => PermissionRegistry::isAdminOnlyPermissionName($permission->name))
                ->values();

            Role::firstOrCreate(['name' => 'admin', 'guard_name' => $guard])
                ->syncPermissions($adminPermissions);
            Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => $guard])
                ->syncPermissions($permissions);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Role grants are not reversible without restoring a database backup.
    }
};
