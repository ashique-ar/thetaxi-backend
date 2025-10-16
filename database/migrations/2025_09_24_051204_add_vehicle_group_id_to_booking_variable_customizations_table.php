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
            $table->uuid('vehicle_group_id')->nullable()->after('session_id');
            $table->index(['booking_id', 'vehicle_group_id', 'variable_name'], 'idx_booking_variable_group');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_variable_customizations', function (Blueprint $table) {
            $table->dropIndex('idx_booking_variable_group');
            $table->dropColumn('vehicle_group_id');
        });
    }
};
