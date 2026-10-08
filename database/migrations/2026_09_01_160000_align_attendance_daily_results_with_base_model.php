<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_attendance_daily_results', function (Blueprint $table) {
            $table->foreignUuid('created_user_id')->nullable()->after('rule_snapshot')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->after('created_user_id')->constrained('users')->restrictOnDelete();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (DB::table('hr_attendance_daily_results')->where(function ($query): void {
            $query->whereNotNull('created_user_id')->orWhereNotNull('updated_user_id')->orWhereNotNull('deleted_at');
        })->exists()) {
            throw new \RuntimeException('Rollback refused: export and reconcile attendance result tracking and deletion evidence first.');
        }

        Schema::table('hr_attendance_daily_results', function (Blueprint $table) {
            $table->dropForeign(['created_user_id']);
            $table->dropForeign(['updated_user_id']);
            $table->dropColumn(['created_user_id','updated_user_id','deleted_at']);
        });
    }
};
