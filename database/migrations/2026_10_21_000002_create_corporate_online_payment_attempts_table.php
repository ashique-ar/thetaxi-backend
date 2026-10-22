<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_online_payment_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('corporate_id')->constrained('corporates')->restrictOnDelete();
            $table->foreignUuid('settlement_id')->constrained('financial_account_settlements')->restrictOnDelete();
            $table->string('order_id')->unique();
            $table->string('invoice_currency', 3);
            $table->decimal('invoice_amount', 20, 4);
            $table->string('gateway_currency', 3);
            $table->decimal('gateway_amount', 20, 0);
            $table->decimal('fx_rate_to_lkr', 20, 12);
            $table->decimal('gateway_fx_rate_to_lkr', 20, 12);
            $table->timestamp('fx_rate_at');
            $table->text('gateway_payload')->nullable();
            $table->string('status', 24)->default('initiated');
            $table->string('gateway_transaction_id')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->uuid('created_user_id')->nullable();
            $table->timestamps();
            $table->index(['settlement_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_online_payment_attempts');
    }
};
