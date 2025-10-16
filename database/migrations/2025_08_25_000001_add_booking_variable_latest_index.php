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
        Schema::table('booking_variable_customizations', function (Blueprint $table) {
            // Add composite index for efficient querying of latest customizations per variable per booking
            $table->index(['booking_id', 'variable_name', 'updated_at'], 'booking_variable_latest_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_variable_customizations', function (Blueprint $table) {
            $table->dropIndex('booking_variable_latest_idx');
        });
    }
};
