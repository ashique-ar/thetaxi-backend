<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('booking_payment_receipts', 'idempotency_key')) {
            Schema::table('booking_payment_receipts', function (Blueprint $table): void {
                $table->string('idempotency_key', 160)->nullable();
                $table->unique('idempotency_key', 'booking_receipt_idempotency_unique');
            });
        }

        Schema::create('financial_settlement_payment_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('settlement_id')->constrained('financial_account_settlements')->restrictOnDelete();
            $table->string('idempotency_key', 160);
            $table->char('request_payload_checksum', 64);
            $table->decimal('source_amount', 20, 4);
            $table->string('source_currency', 3);
            $table->decimal('lkr_amount', 20, 4);
            $table->decimal('fx_rate_to_lkr', 20, 10);
            $table->timestamp('fx_rate_at');
            $table->string('fx_source', 120);
            $table->string('payment_method', 50);
            $table->string('reference', 160)->nullable();
            $table->timestamp('received_at');
            $table->text('notes')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['settlement_id', 'idempotency_key'], 'financial_settlement_payment_idempotency_unique');
            $table->index(['settlement_id', 'received_at'], 'financial_settlement_payment_history_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('financial_settlement_payment_events')->exists()
            || DB::table('booking_payment_receipts')->whereNotNull('idempotency_key')->exists()) {
            throw new \RuntimeException(
                'Cannot remove persistent payment idempotency after payment evidence exists. Disable the feature and retain the additive schema.'
            );
        }

        Schema::dropIfExists('financial_settlement_payment_events');
        Schema::table('booking_payment_receipts', function (Blueprint $table): void {
            $table->dropUnique('booking_receipt_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
