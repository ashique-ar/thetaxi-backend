<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_direct_role_grants') || !Schema::hasTable('user_context_roles')) {
            return;
        }

        $userId = DB::table('users')->where('email', 'admin@casons.lk')->value('id');
        $roleId = DB::table('roles')->where('name', 'admin')->where('guard_name', 'api')->value('id');
        if (!$userId || !$roleId
            || !DB::table('model_has_roles')->where('model_type', User::class)
                ->where('model_id', $userId)->where('role_id', $roleId)->exists()) {
            return;
        }

        $hasContextSource = DB::table('user_context_roles as grants')
            ->join('user_contexts', 'user_contexts.id', '=', 'grants.user_context_id')
            ->where('user_contexts.user_id', $userId)->where('grants.role_id', $roleId)->exists();
        if ($hasContextSource) {
            return;
        }

        DB::table('user_direct_role_grants')->insertOrIgnore([
            'user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep provenance evidence; deleting it could make later role revocation unsafe.
    }
};
