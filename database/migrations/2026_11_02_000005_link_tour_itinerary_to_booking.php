<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tour_itineraries', function (Blueprint $table): void {
            $table->foreignUuid('booking_id')->nullable()->unique()->constrained('bookings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tour_itineraries')->whereNotNull('booking_id')->exists()) {
            throw new RuntimeException('Preserve Tour-to-booking links before rolling back this schema.');
        }

        Schema::table('tour_itineraries', function (Blueprint $table): void {
            $table->dropForeign(['booking_id']);
            $table->dropUnique(['booking_id']);
            $table->dropColumn('booking_id');
        });
    }
};
