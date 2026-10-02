<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('hr_attendance_daily_results', function (Blueprint $table) {
            $table->dropForeign(['created_user_id']);
            $table->dropForeign(['updated_user_id']);
            $table->dropColumn(['created_user_id','updated_user_id','deleted_at']);
        });
    }
};
