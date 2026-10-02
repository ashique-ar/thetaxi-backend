<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        foreach (['hr_attendance_raw_events','hr_attendance_devices','hr_attendance_connectors'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('updated_user_id');
                $table->dropSoftDeletes();
            });
        }
    }
};
