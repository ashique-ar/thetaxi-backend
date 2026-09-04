<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hr_attendance_raw_events', 'created_user_id')) {
            Schema::table('hr_attendance_raw_events', function (Blueprint $table) {
                $table->foreignUuid('created_user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hr_attendance_raw_events', 'created_user_id')) {
            Schema::table('hr_attendance_raw_events', function (Blueprint $table) {
                $table->dropConstrainedForeignId('created_user_id');
            });
        }
    }
};
