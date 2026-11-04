<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_maintenance_schedules', function (Blueprint $table): void {
            $table->string('status')->default('scheduled');
            $table->text('notes')->nullable();
            $table->foreignUuid('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignUuid('booking_item_id')->nullable()->constrained('booking_items')->nullOnDelete();
            $table->foreignUuid('booking_qc_id')->nullable()->constrained('booking_qcs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_maintenance_schedules', function (Blueprint $table): void {
            $table->dropForeign(['booking_id']);
            $table->dropForeign(['booking_item_id']);
            $table->dropForeign(['booking_qc_id']);
            $table->dropColumn(['status', 'notes', 'booking_id', 'booking_item_id', 'booking_qc_id']);
        });
    }
};
