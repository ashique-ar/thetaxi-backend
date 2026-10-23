<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->uuid('base_rate_package_id')->nullable()->after('price_multiplier');
            $table->foreign('base_rate_package_id')->references('id')->on('service_packages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropForeign(['base_rate_package_id']);
            $table->dropColumn('base_rate_package_id');
        });
    }
};
