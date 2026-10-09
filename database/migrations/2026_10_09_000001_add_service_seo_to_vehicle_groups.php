<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('vehicle_groups', 'service_seo')) {
            Schema::table('vehicle_groups', function (Blueprint $table) {
                $table->json('service_seo')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vehicle_groups', 'service_seo')) {
            Schema::table('vehicle_groups', function (Blueprint $table) {
                $table->dropColumn('service_seo');
            });
        }
    }
};
