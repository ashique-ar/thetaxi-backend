<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds minimum_km field to service_types table.
     * This field defines the minimum kilometers that will be charged for a booking,
     * even if the actual journey distance is less than this value.
     */
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->decimal('minimum_km', 10, 2)->nullable()->default(20)->after('priority')
                ->comment('Minimum KM to charge for pricing calculations. If journey distance is below this, charge as this value.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->dropColumn('minimum_km');
        });
    }
};
