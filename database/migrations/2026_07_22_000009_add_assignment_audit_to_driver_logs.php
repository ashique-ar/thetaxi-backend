<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_logs', function (Blueprint $table): void {
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
        });

        DB::table('driver_logs')
            ->whereNotNull('driver_id')
            ->whereNull('assigned_at')
            ->update([
                'assigned_at' => DB::raw('created_at'),
            ]);

        DB::table('driver_logs')
            ->whereNotNull('driver_id')
            ->whereIn('created_user_id', DB::table('users')->select('id'))
            ->whereNull('assigned_by')
            ->update(['assigned_by' => DB::raw('created_user_id')]);
    }

    public function down(): void
    {
        Schema::table('driver_logs', function (Blueprint $table): void {
            $table->dropForeign(['assigned_by']);
            $table->dropColumn(['assigned_by', 'assigned_at']);
        });
    }
};
