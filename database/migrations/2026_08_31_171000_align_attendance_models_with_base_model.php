<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['hr_attendance_connectors','hr_attendance_devices','hr_attendance_raw_events'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        $tables = ['hr_attendance_raw_events','hr_attendance_devices','hr_attendance_connectors'];
        foreach ($tables as $tableName) {
            if (DB::table($tableName)->where(function ($query): void {
                $query->whereNotNull('updated_user_id')->orWhereNotNull('deleted_at');
            })->exists()) {
                throw new \RuntimeException("Rollback refused: export and reconcile attendance tracking/deletion history for {$tableName} first.");
            }
        }

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('updated_user_id');
                $table->dropSoftDeletes();
            });
        }
    }
};
