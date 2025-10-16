<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add KM limits to slab definitions
        Schema::table('vehicle_pricing_slab_definitions', function (Blueprint $table) {
            $table->integer('max_km_per_day')->nullable()->after('max_days')->comment('Maximum kilometers allowed per day for this slab');
            $table->integer('max_km_per_package')->nullable()->after('max_km_per_day')->comment('Maximum kilometers allowed for the entire package/duration');
        });

        // Add indexes for the new columns
        Schema::table('vehicle_pricing_slab_definitions', function (Blueprint $table) {
            $table->index('max_km_per_day');
            $table->index('max_km_per_package');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_pricing_slab_definitions', function (Blueprint $table) {
            $table->dropIndex(['max_km_per_day']);
            $table->dropIndex(['max_km_per_package']);
            $table->dropColumn(['max_km_per_day', 'max_km_per_package']);
        });
    }
};
