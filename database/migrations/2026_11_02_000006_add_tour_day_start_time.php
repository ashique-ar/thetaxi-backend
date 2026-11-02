<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tour_itinerary_days', function (Blueprint $table): void {
            $table->time('start_time')->default('09:00:00');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tour_itinerary_days')
            && DB::table('tour_itinerary_days')->where('start_time', '!=', '09:00:00')->exists()) {
            throw new RuntimeException('Preserve customized Tour day start times before rolling back this schema.');
        }

        Schema::table('tour_itinerary_days', function (Blueprint $table): void {
            $table->dropColumn('start_time');
        });
    }
};
