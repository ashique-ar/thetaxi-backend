<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('payment_responsibility')->default('customer')->after('payment_reference');
            $table->string('payment_collection_method')->default('cash_to_driver')->after('payment_responsibility');
            $table->string('payment_collection_status')->default('pending')->after('payment_collection_method');
            $table->decimal('payment_collected_amount', 12, 2)->nullable()->after('payment_collection_status');
            $table->timestamp('payment_collected_at')->nullable()->after('payment_collected_amount');
            $table->uuid('payment_collected_by_driver_id')->nullable()->index()->after('payment_collected_at');
            $table->text('payment_notes')->nullable()->after('payment_collected_by_driver_id');

            $table->index(['payment_collection_method', 'payment_collection_status'], 'bookings_payment_collection_idx');
            $table->index(['payment_responsibility', 'payment_collection_status'], 'bookings_payment_resp_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_payment_collection_idx');
            $table->dropIndex('bookings_payment_resp_status_idx');
            $table->dropColumn([
                'payment_responsibility',
                'payment_collection_method',
                'payment_collection_status',
                'payment_collected_amount',
                'payment_collected_at',
                'payment_collected_by_driver_id',
                'payment_notes',
            ]);
        });
    }
};
