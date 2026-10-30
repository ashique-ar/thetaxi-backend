<?php

use App\Models\User;
use App\Services\CorporateRoleStarterService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const CORPORATE_AUDIT_PERMISSION = 'corporate.audit-logs.view';
    private const SYSTEM_AUDIT_PERMISSION = 'view_audit_log';

    public function up(): void
    {
        DB::transaction(function (): void {
            $guard = config('permissions.canonical_guard', 'api');
            $corporatePermission = Permission::findOrCreate(self::CORPORATE_AUDIT_PERMISSION, $guard);
            $systemPermission = Permission::query()
                ->where('name', self::SYSTEM_AUDIT_PERMISSION)
                ->where('guard_name', $guard)
                ->first();

            if (! $systemPermission) {
                return;
            }

            Role::query()->where('guard_name', $guard)->get()
                ->filter(fn (Role $role) => CorporateRoleStarterService::isCorporateRoleName($role->name))
                ->each(function (Role $role) use ($corporatePermission, $systemPermission): void {
                    if ($role->permissions()->whereKey($systemPermission->id)->exists()) {
                        $role->givePermissionTo($corporatePermission);
                        $role->revokePermissionTo($systemPermission);
                    }
                });

            $contextGrants = DB::table('user_context_permission_grants as grants')
                ->join('user_contexts', 'user_contexts.id', '=', 'grants.user_context_id')
                ->where('user_contexts.context_type', 'corporate')
                ->where('grants.permission_id', $systemPermission->id)
                ->get(['grants.user_context_id', 'user_contexts.user_id', 'grants.created_at', 'grants.updated_at']);

            foreach ($contextGrants as $grant) {
                DB::table('user_context_permission_grants')->insertOrIgnore([
                    'user_context_id' => $grant->user_context_id,
                    'permission_id' => $corporatePermission->id,
                    'created_at' => $grant->created_at,
                    'updated_at' => $grant->updated_at,
                ]);
            }

            $contextIds = $contextGrants->pluck('user_context_id')->unique()->all();
            if ($contextIds !== []) {
                DB::table('user_context_permission_grants')
                    ->whereIn('user_context_id', $contextIds)
                    ->where('permission_id', $systemPermission->id)
                    ->delete();

                foreach ($contextGrants->pluck('user_id')->unique() as $userId) {
                    $hasOtherTrackedSource = DB::table('user_direct_permission_grants')
                        ->where('user_id', $userId)
                        ->where('permission_id', $systemPermission->id)
                        ->exists()
                        || DB::table('user_context_permission_grants as grants')
                            ->join('user_contexts', 'user_contexts.id', '=', 'grants.user_context_id')
                            ->where('user_contexts.user_id', $userId)
                            ->where('grants.permission_id', $systemPermission->id)
                            ->exists();

                    if (! $hasOtherTrackedSource) {
                        DB::table('model_has_permissions')
                            ->where('permission_id', $systemPermission->id)
                            ->where('model_type', User::class)
                            ->where('model_id', $userId)
                            ->delete();
                    }
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The scoped corporate grant must not be converted back into system audit access.
    }
};
