<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->foreignUuid('booking_id')->nullable()->after('customer_id')
                ->constrained('bookings')->restrictOnDelete();
            $table->index(['company_id', 'booking_id'], 'inquiries_company_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->dropIndex('inquiries_company_booking_idx');
            $table->dropConstrainedForeignId('booking_id');
        });
    }
};
