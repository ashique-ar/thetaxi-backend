<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_packages', function ($table): void {
            $table->json('tour_itinerary_template')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('service_packages')->whereNotNull('tour_itinerary_template')->exists()) {
            throw new RuntimeException('Preserve configured package itineraries before removing the template column.');
        }

        Schema::table('service_packages', function ($table): void {
            $table->dropColumn('tour_itinerary_template');
        });
    }
};
