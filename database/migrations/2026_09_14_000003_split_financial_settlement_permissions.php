<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const PERMISSIONS = [
        'financial-settlements.issue',
        'financial-settlements.receive',
        'financial-settlements.allocate',
        'financial-settlements.adjust',
        'financial-settlements.dispute',
        'financial-settlements.follow-up',
    ];

    public function up(): void
    {
        foreach (['api', 'web'] as $guard) {
            foreach (self::PERMISSIONS as $name) {
                DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => $guard, 'created_at' => now(), 'updated_at' => now()]);
            }
            $permissionIds = DB::table('permissions')->where('guard_name', $guard)->whereIn('name', self::PERMISSIONS)->pluck('id');
            $roleIds = DB::table('roles')->where('guard_name', $guard)->whereIn('name', ['admin', 'sub-admin', 'accountant'])->pluck('id');
            foreach ($roleIds as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
