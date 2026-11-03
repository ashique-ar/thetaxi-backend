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
            $table->unsignedSmallInteger('child_seat_count')->default(0)->after('child_count');
        });
    }

    public function down(): void
    {
        if (DB::table('tour_itineraries')->where('child_seat_count', '>', 0)->exists()) {
            throw new RuntimeException('Preserve Tour child-seat requirements before rolling back their storage.');
        }

        Schema::table('tour_itineraries', fn (Blueprint $table) => $table->dropColumn('child_seat_count'));
    }
};
