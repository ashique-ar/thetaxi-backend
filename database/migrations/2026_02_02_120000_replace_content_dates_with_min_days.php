<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cms_contents', function (Blueprint $table) {
            // Add min_days (defaults to 1)
            $table->unsignedInteger('min_days')->default(1)->after('service_type');

            // Drop explicit date/time fields (they are no longer used)
            if (Schema::hasColumn('cms_contents', 'pickup_date')) {
                $table->dropColumn(['pickup_date']);
            }
            if (Schema::hasColumn('cms_contents', 'pickup_time')) {
                $table->dropColumn(['pickup_time']);
            }
            if (Schema::hasColumn('cms_contents', 'dropoff_date')) {
                $table->dropColumn(['dropoff_date']);
            }
            if (Schema::hasColumn('cms_contents', 'dropoff_time')) {
                $table->dropColumn(['dropoff_time']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('cms_contents', function (Blueprint $table) {
            // Recreate date/time columns (nullable)
            $table->date('pickup_date')->nullable()->after('service_type');
            $table->time('pickup_time')->nullable()->after('pickup_date');
            $table->date('dropoff_date')->nullable()->after('pickup_time');
            $table->time('dropoff_time')->nullable()->after('dropoff_date');

            // Drop min_days
            if (Schema::hasColumn('cms_contents', 'min_days')) {
                $table->dropColumn('min_days');
            }
        });
    }
};
