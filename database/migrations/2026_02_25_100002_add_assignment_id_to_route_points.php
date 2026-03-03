<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds assignment_id to route_points for trip-specific location tracking.
     */
    public function up(): void
    {
        Schema::table('route_points', function (Blueprint $table) {
            $table->uuid('assignment_id')->nullable()->after('session_id');

            // Composite index for trip-specific route point queries
            $table->index(['assignment_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('route_points', function (Blueprint $table) {
            $table->dropForeign(['assignment_id']);
            $table->dropIndex(['assignment_id', 'recorded_at']);
            $table->dropColumn('assignment_id');
        });
    }
};
