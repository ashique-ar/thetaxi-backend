<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_webhook_events', function (Blueprint $table): void {
            $table->foreignUuid('linked_booking_id')->nullable()->after('linked_inquiry_id')
                ->constrained('bookings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('integration_webhook_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('linked_booking_id');
        });
    }
};
