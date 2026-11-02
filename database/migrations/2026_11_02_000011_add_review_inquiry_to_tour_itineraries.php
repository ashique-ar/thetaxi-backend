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
            $table->foreignUuid('review_inquiry_id')->nullable()->after('booking_id')
                ->unique()->constrained('inquiries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tour_itineraries')->whereNotNull('review_inquiry_id')->exists()) {
            throw new RuntimeException('Preserve Tour review inquiries before removing their itinerary link.');
        }

        Schema::table('tour_itineraries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('review_inquiry_id');
        });
    }
};
