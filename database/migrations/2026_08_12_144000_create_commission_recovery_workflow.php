<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_commission_recovery_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('commission_decision_id')->constrained('sales_commission_decisions')->restrictOnDelete();
            $table->foreignUuid('payment_adjustment_id')->unique()->constrained('booking_payment_adjustments')->restrictOnDelete();
            $table->foreignUuid('beneficiary_staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->decimal('adjusted_source_amount', 20, 4);
            $table->decimal('proposed_recovery_lkr', 20, 4);
            $table->string('status', 30)->default('pending_review');
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'opened_at'], 'commission_recovery_review_queue_idx');
        });

        Schema::create('sales_commission_recovery_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recovery_case_id')->unique()->constrained('sales_commission_recovery_cases')->restrictOnDelete();
            $table->string('decision', 20);
            $table->decimal('commission_adjustment_lkr', 20, 4);
            $table->decimal('waived_recovery_lkr', 20, 4)->default(0);
            $table->text('reason');
            $table->foreignUuid('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->string('idempotency_key', 160)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_commission_recovery_decisions');
        Schema::dropIfExists('sales_commission_recovery_cases');
    }
};
