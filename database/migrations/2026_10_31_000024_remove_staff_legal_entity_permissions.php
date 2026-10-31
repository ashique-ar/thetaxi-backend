<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'staff.view-legal-entity' => 'staff.view-all',
        'staff.edit-legal-entity' => 'staff.edit-all',
        'staff.terminate-legal-entity' => 'staff.terminate-all',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as $legacyName => $installationName) {
            $legacyPermissions = DB::table('permissions')->where('name', $legacyName)->get(['id', 'guard_name']);
            foreach ($legacyPermissions as $legacy) {
                DB::table('permissions')->insertOrIgnore([
                    'name' => $installationName,
                    'guard_name' => $legacy->guard_name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $targetId = DB::table('permissions')->where('name', $installationName)
                    ->where('guard_name', $legacy->guard_name)->value('id');
                if (! $targetId) {
                    throw new RuntimeException("Could not create installation-wide permission {$installationName}.");
                }

                $this->moveAssignments('role_has_permissions', (int) $legacy->id, (int) $targetId);
                $this->moveAssignments('model_has_permissions', (int) $legacy->id, (int) $targetId);
                $this->moveAssignments('user_context_permission_grants', (int) $legacy->id, (int) $targetId);
                $this->moveAssignments('user_direct_permission_grants', (int) $legacy->id, (int) $targetId);
                DB::table('permissions')->where('id', $legacy->id)->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function moveAssignments(string $table, int $fromId, int $toId): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'permission_id')) {
            return;
        }

        foreach (DB::table($table)->where('permission_id', $fromId)->get() as $assignment) {
            $row = (array) $assignment;
            $row['permission_id'] = $toId;
            DB::table($table)->insertOrIgnore($row);
        }
        DB::table($table)->where('permission_id', $fromId)->delete();
    }

    public function down(): void
    {
        throw new RuntimeException('Installation-wide Staff permissions cannot be restored to legal-entity scope.');
    }
};
