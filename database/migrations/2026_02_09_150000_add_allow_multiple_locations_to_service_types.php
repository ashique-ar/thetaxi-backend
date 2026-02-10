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
        Schema::table('service_types', function (Blueprint $table) {
            $table->boolean('allow_multiple_pickup_locations')
                ->default(false)
                ->after('allow_return_trip')
                ->comment('Allow multiple pickup and dropoff locations for complex routes');
            $table->boolean('allow_multiple_dropoff_locations')
                ->default(false)
                ->after('allow_multiple_pickup_locations')
                ->comment('Allow multiple pickup and dropoff locations for complex routes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->dropColumn(['allow_multiple_pickup_locations', 'allow_multiple_dropoff_locations']);
        });
    }
};
