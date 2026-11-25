<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_payment_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('created_user_id')->nullable()->index();
            $table->string('order_id', 40)->unique();
            $table->decimal('amount', 20, 4);
            $table->string('currency', 3);
            $table->decimal('gateway_amount', 20, 0);
            $table->string('gateway_currency', 3);
            $table->decimal('fx_rate_to_lkr', 20, 12);
            $table->decimal('gateway_fx_rate_to_lkr', 20, 12);
            $table->timestamp('fx_rate_at');
            $table->text('gateway_payload')->nullable();
            $table->string('status', 24)->default('initiated');
            $table->string('gateway_transaction_id')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['created_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_payment_links');
    }
};
