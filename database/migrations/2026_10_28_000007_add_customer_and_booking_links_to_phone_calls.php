<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table): void {
            $table->foreignUuid('customer_id')->nullable()->after('id')->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('booking_id')->nullable()->after('customer_id')->constrained('bookings')->restrictOnDelete();
            $table->index(['customer_id', 'call_time'], 'phone_calls_customer_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table): void {
            $table->dropIndex('phone_calls_customer_time_idx');
            $table->dropConstrainedForeignId('booking_id');
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
