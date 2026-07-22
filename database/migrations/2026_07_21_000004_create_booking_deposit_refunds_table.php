<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_deposit_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_payment_receipt_id')->constrained('booking_payment_receipts')->cascadeOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('refund_method');
            $table->string('reference')->nullable();
            $table->dateTime('refunded_at');
            $table->foreignUuid('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['booking_id', 'refunded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_deposit_refunds');
    }
};
