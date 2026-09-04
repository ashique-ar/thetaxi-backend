<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payment_receipts', function (Blueprint $table) {
            $table->string('initial_finality_status', 30)->default('confirmed');
        });

        Schema::create('booking_payment_receipt_finality_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignUuid('booking_payment_receipt_id')->constrained('booking_payment_receipts')->restrictOnDelete();
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->text('reason');
            $table->string('evidence_reference', 500)->nullable();
            $table->string('idempotency_key', 160);
            $table->foreignUuid('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['booking_payment_receipt_id', 'idempotency_key'], 'receipt_finality_event_idempotency_unique');
            $table->index(['booking_payment_receipt_id', 'occurred_at'], 'receipt_finality_event_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payment_receipt_finality_events');
        Schema::table('booking_payment_receipts', fn (Blueprint $table) => $table->dropColumn('initial_finality_status'));
    }
};
