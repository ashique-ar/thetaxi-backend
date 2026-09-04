<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('corporate_report_schedules')
            && ! Schema::hasColumn('corporate_report_schedules', 'deleted_at')) {
            Schema::table('corporate_report_schedules', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('corporate_report_schedules')
            && Schema::hasColumn('corporate_report_schedules', 'deleted_at')) {
            Schema::table('corporate_report_schedules', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
