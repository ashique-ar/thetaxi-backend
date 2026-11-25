<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        $employeeRoleIds = $this->corporateEmployeeRoleIds();
        if ($employeeRoleIds === []) {
            return;
        }

        $selfView = Permission::findOrCreate('staff-transport.self.view', 'api');
        $view = Permission::query()->where('name', 'staff-transport.view')->where('guard_name', 'api')->first();

        DB::table('role_has_permissions')->insertOrIgnore(array_map(
            fn (int $roleId): array => ['role_id' => $roleId, 'permission_id' => $selfView->id],
            $employeeRoleIds,
        ));

        if ($view) {
            DB::table('role_has_permissions')
                ->whereIn('role_id', $employeeRoleIds)
                ->where('permission_id', $view->id)
                ->delete();
        }

        app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $employeeRoleIds = $this->corporateEmployeeRoleIds();
        if ($employeeRoleIds === []) {
            return;
        }

        $selfView = Permission::query()->where('name', 'staff-transport.self.view')->where('guard_name', 'api')->first();
        $view = Permission::findOrCreate('staff-transport.view', 'api');

        DB::table('role_has_permissions')->insertOrIgnore(array_map(
            fn (int $roleId): array => ['role_id' => $roleId, 'permission_id' => $view->id],
            $employeeRoleIds,
        ));

        if ($selfView) {
            DB::table('role_has_permissions')
                ->whereIn('role_id', $employeeRoleIds)
                ->where('permission_id', $selfView->id)
                ->delete();
        }

        app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<int> */
    private function corporateEmployeeRoleIds(): array
    {
        return DB::table('roles')
            ->where('guard_name', 'api')
            ->where('name', 'like', 'Corporate_%_Employee')
            ->get(['id', 'name'])
            ->filter(fn (object $role): bool => preg_match('/^Corporate_[0-9a-f-]{36}_Employee$/i', $role->name) === 1)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
};
