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
            $table->json('custom_answers')->nullable();
            $table->json('custom_fields_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('tour_itineraries')->whereNotNull('custom_answers')->orWhereNotNull('custom_fields_snapshot')->exists()) {
            throw new RuntimeException('Preserve configured Tour answers before rolling back their storage.');
        }

        Schema::table('tour_itineraries', fn (Blueprint $table) => $table->dropColumn(['custom_answers', 'custom_fields_snapshot']));
    }
};
