<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $apiPermissionIds = [];
            foreach (DB::table('permissions')->where('guard_name', 'web')->get() as $webPermission) {
                $apiPermission = DB::table('permissions')
                    ->where('name', $webPermission->name)
                    ->where('guard_name', 'api')
                    ->first();

                if (! $apiPermission) {
                    $attributes = (array) $webPermission;
                    unset($attributes['id']);
                    $attributes['guard_name'] = 'api';
                    $apiPermissionId = DB::table('permissions')->insertGetId($attributes);
                } else {
                    $apiPermissionId = $apiPermission->id;
                }

                $apiPermissionIds[$webPermission->id] = $apiPermissionId;
            }

            if (Schema::hasTable('model_has_permissions')) {
                foreach (DB::table('model_has_permissions')->whereIn('permission_id', array_keys($apiPermissionIds))->get() as $grant) {
                    $attributes = (array) $grant;
                    $attributes['permission_id'] = $apiPermissionIds[$grant->permission_id];
                    DB::table('model_has_permissions')->insertOrIgnore($attributes);
                }
            }

            foreach (DB::table('roles')->where('guard_name', 'web')->get() as $webRole) {
                $apiRoleId = DB::table('roles')
                    ->where('name', $webRole->name)
                    ->where('guard_name', 'api')
                    ->value('id');

                if (! $apiRoleId) {
                    $attributes = [
                        'name' => $webRole->name,
                        'guard_name' => 'api',
                        'created_at' => $webRole->created_at,
                        'updated_at' => now(),
                    ];
                    foreach (['description', 'context_types', 'auto_assign_contexts'] as $column) {
                        if (Schema::hasColumn('roles', $column)) {
                            $attributes[$column] = $webRole->{$column} ?? null;
                        }
                    }
                    $apiRoleId = DB::table('roles')->insertGetId($attributes);
                }

                foreach (DB::table('role_has_permissions')->where('role_id', $webRole->id)->get() as $grant) {
                    $apiPermissionId = $apiPermissionIds[$grant->permission_id] ?? null;
                    if ($apiPermissionId) {
                        DB::table('role_has_permissions')->insertOrIgnore([
                            'permission_id' => $apiPermissionId,
                            'role_id' => $apiRoleId,
                        ]);
                    }
                }

                $this->moveRoleLinks('model_has_roles', 'role_id', $webRole->id, $apiRoleId);
                $this->moveRoleLinks('user_context_roles', 'role_id', $webRole->id, $apiRoleId);
                $this->moveRoleLinks('user_direct_role_grants', 'role_id', $webRole->id, $apiRoleId);
            }

            DB::table('roles')->where('guard_name', 'web')->delete();
            DB::table('permissions')->where('guard_name', 'web')->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        throw new RuntimeException('Web role and permission consolidation cannot be reversed without restoring a database backup.');
    }

    private function moveRoleLinks(string $table, string $roleColumn, int $webRoleId, int $apiRoleId): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach (DB::table($table)->where($roleColumn, $webRoleId)->get() as $link) {
            $attributes = (array) $link;
            unset($attributes['id']);
            $attributes[$roleColumn] = $apiRoleId;
            DB::table($table)->insertOrIgnore($attributes);
        }
    }
};
