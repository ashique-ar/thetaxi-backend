<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vehicle_assignments', 'booking_item_id')) return;

        Schema::table('vehicle_assignments', function (Blueprint $table): void {
            $table->uuid('booking_item_id')->nullable()->after('booking_id');
            // Keep the trip identifier as historical evidence if its source row is later removed.
            $table->index(['booking_id', 'booking_item_id'], 'vehicle_assignments_booking_item_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('vehicle_assignments', 'booking_item_id')) return;

        Schema::table('vehicle_assignments', function (Blueprint $table): void {
            $table->dropIndex('vehicle_assignments_booking_item_idx');
            $table->dropColumn('booking_item_id');
        });
    }
};
