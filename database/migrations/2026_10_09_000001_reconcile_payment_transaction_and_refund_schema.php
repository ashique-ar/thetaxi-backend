<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_transactions', 'booking_id')) {
                $table->uuid('booking_id')->nullable()->index();
            }
            if (! Schema::hasColumn('payment_transactions', 'currency')) {
                $table->string('currency', 3)->nullable();
            }
            if (! Schema::hasColumn('payment_transactions', 'payment_method')) {
                $table->string('payment_method', 50)->nullable();
            }
            if (! Schema::hasColumn('payment_transactions', 'gateway_transaction_id')) {
                $table->string('gateway_transaction_id')->nullable()->index();
            }
            if (! Schema::hasColumn('payment_transactions', 'payment_id')) {
                $table->string('payment_id')->nullable();
            }
        });

        if (! Schema::hasTable('payment_refunds')) {
            Schema::create('payment_refunds', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('transaction_id');
                $table->decimal('amount', 12, 2);
                $table->string('reason', 500);
                $table->string('status', 30)->default('pending');
                $table->string('gateway_refund_id')->nullable();
                $table->text('notes')->nullable();
                $table->string('idempotency_key', 160);
                $table->char('request_payload_checksum', 64);
                $table->uuid('created_by')->nullable();
                $table->timestamps();

                $table->unique(['transaction_id', 'idempotency_key'], 'payment_refunds_transaction_key_unique');
                $table->index(['transaction_id', 'status'], 'payment_refunds_transaction_status_idx');
                $table->unique(['transaction_id', 'gateway_refund_id'], 'payment_refunds_gateway_reference_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');

        $columns = ['booking_id', 'currency', 'payment_method', 'gateway_transaction_id', 'payment_id'];
        foreach ($columns as $column) {
            if (Schema::hasColumn('payment_transactions', $column)) {
                Schema::table('payment_transactions', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
