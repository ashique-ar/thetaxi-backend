<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds trigger delivery tracking columns to booking_dispatches
     * for recording when and how driver mobile triggers were delivered.
     */
    public function up(): void
    {
        Schema::table('booking_dispatches', function (Blueprint $table) {
            $table->timestamp('trigger_delivered_at')->nullable()->after('is_self_driven');
            $table->string('trigger_delivery_channel', 20)->nullable()->after('trigger_delivered_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_dispatches', function (Blueprint $table) {
            $table->dropColumn(['trigger_delivered_at', 'trigger_delivery_channel']);
        });
    }
};
