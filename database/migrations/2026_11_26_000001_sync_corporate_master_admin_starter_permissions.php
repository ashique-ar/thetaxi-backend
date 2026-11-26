<?php

use App\Services\CorporateRoleStarterService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guard = config('permissions.canonical_guard', 'api');
        $masterPermissions = CorporateRoleStarterService::TEMPLATES['Corporate_Master_Admin']['permissions'];

        $permissionIds = collect($masterPermissions)
            ->mapWithKeys(fn (string $name): array => [
                $name => (int) Permission::findOrCreate($name, $guard)->id,
            ]);

        $masterRoleIds = DB::table('roles')
            ->where('guard_name', $guard)
            ->where(function ($query): void {
                $query->where('name', 'Corporate_Master_Admin')
                    ->orWhere('name', 'like', 'Corporate_%_Master_Administrator');
            })
            ->get(['id', 'name'])
            ->filter(fn (object $role): bool => $role->name === 'Corporate_Master_Admin'
                || preg_match('/^Corporate_[0-9a-f-]{36}_Master_Administrator$/i', $role->name) === 1)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($masterRoleIds !== []) {
            $rows = [];
            foreach ($masterRoleIds as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    $rows[] = ['role_id' => $roleId, 'permission_id' => $permissionId];
                }
            }
            DB::table('role_has_permissions')->insertOrIgnore($rows);
        }

        $coordinatorRoleIds = DB::table('roles')
            ->where('guard_name', $guard)
            ->where(function ($query): void {
                $query->where('name', 'Transport_Coordinator')
                    ->orWhere('name', 'like', 'Corporate_%_Transport_Coordinator');
            })
            ->get(['id', 'name'])
            ->filter(fn (object $role): bool => $role->name === 'Transport_Coordinator'
                || preg_match('/^Corporate_[0-9a-f-]{36}_Transport_Coordinator$/i', $role->name) === 1)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $selfViewId = $permissionIds->get('staff-transport.self.view');
        if ($coordinatorRoleIds !== [] && $selfViewId) {
            DB::table('role_has_permissions')->insertOrIgnore(array_map(
                fn (int $roleId): array => ['role_id' => $roleId, 'permission_id' => $selfViewId],
                $coordinatorRoleIds,
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Additive starter-permission backfill is intentionally non-destructive.
    }
};
