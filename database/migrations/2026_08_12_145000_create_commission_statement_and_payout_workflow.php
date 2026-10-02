<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_commission_statements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('cycle_version_id')->constrained('sales_commission_cycle_versions')->restrictOnDelete();
            $table->foreignUuid('cycle_assignment_id')->constrained('sales_commission_cycle_assignments')->restrictOnDelete();
            $table->string('statement_number', 100)->unique();
            $table->unsignedInteger('version')->default(1);
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('cutoff_at');
            $table->string('timezone', 80);
            $table->string('payout_currency', 3)->default('LKR');
            $table->decimal('opening_carry_forward_lkr', 20, 4)->default(0);
            $table->decimal('gross_earnings_lkr', 20, 4)->default(0);
            $table->decimal('adjustment_credits_lkr', 20, 4)->default(0);
            $table->decimal('recovery_deductions_lkr', 20, 4)->default(0);
            $table->decimal('other_deductions_lkr', 20, 4)->default(0);
            $table->decimal('contested_hold_lkr', 20, 4)->default(0);
            $table->decimal('net_payable_lkr', 20, 4)->default(0);
            $table->decimal('paid_lkr', 20, 4)->default(0);
            $table->decimal('closing_carry_forward_lkr', 20, 4)->default(0);
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('state_version')->default(1);
            $table->foreignUuid('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('prepared_at');
            $table->string('generation_idempotency_key', 160)->unique();
            $table->char('generation_payload_checksum', 64);
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'staff_id', 'cycle_version_id', 'period_start', 'period_end', 'version'], 'commission_statement_period_version_unique');
            $table->index(['company_id', 'status', 'period_end'], 'commission_statement_status_period_idx');
        });

        Schema::create('sales_commission_statement_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('statement_id')->constrained('sales_commission_statements')->restrictOnDelete();
            $table->string('line_type', 40);
            $table->string('source_type', 80);
            $table->uuid('source_id')->nullable();
            $table->string('description', 500);
            $table->decimal('gross_lkr', 20, 4)->default(0);
            $table->decimal('deduction_lkr', 20, 4)->default(0);
            $table->decimal('net_lkr', 20, 4);
            $table->string('line_status', 30)->default('included');
            $table->string('hold_code', 80)->nullable();
            $table->json('calculation_snapshot');
            $table->char('snapshot_checksum', 64);
            $table->timestamps();
            $table->unique(['statement_id', 'source_type', 'source_id'], 'commission_statement_source_unique');
            $table->index(['statement_id', 'line_type']);
        });

        Schema::create('sales_commission_statement_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('statement_id')->constrained('sales_commission_statements')->restrictOnDelete();
            $table->unsignedInteger('from_version');
            $table->unsignedInteger('to_version');
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 160);
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['statement_id', 'idempotency_key'], 'commission_statement_event_idempotency_unique');
        });

        Schema::create('sales_commission_disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('statement_id')->constrained('sales_commission_statements')->restrictOnDelete();
            $table->foreignUuid('statement_line_id')->constrained('sales_commission_statement_lines')->restrictOnDelete();
            $table->foreignUuid('raised_by_staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('category', 60);
            $table->text('reason');
            $table->foreignUuid('evidence_file_id')->nullable()->constrained('domain_evidence_files')->restrictOnDelete();
            $table->decimal('contested_amount_lkr', 20, 4);
            $table->string('status', 30)->default('open');
            $table->timestamp('raised_at');
            $table->timestamp('response_due_at');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 30)->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->string('resolution_idempotency_key', 160)->nullable()->unique();
            $table->char('resolution_payload_checksum', 64)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'response_due_at'], 'commission_dispute_sla_idx');
        });

        Schema::create('sales_commission_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('payout_number', 100)->unique();
            $table->decimal('amount_lkr', 20, 4);
            $table->string('payment_method', 50);
            $table->text('payment_account_snapshot');
            $table->string('payment_reference', 160);
            $table->timestamp('paid_at');
            $table->foreignUuid('evidence_file_id')->nullable()->constrained('domain_evidence_files')->restrictOnDelete();
            $table->string('status', 30)->default('confirmed');
            $table->string('accounting_status', 30)->default('pending_delivery');
            $table->foreignUuid('paid_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 160)->unique();
            $table->char('request_payload_checksum', 64);
            $table->foreignUuid('reverses_payout_id')->nullable()->unique();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'payment_reference'], 'commission_payout_reference_unique');
        });

        // Postgres cannot resolve a self-referencing foreign key added inside the
        // same Schema::create() — the primary key it needs to reference is not yet
        // visible to that ALTER-TABLE-compiled constraint. A separate Schema::table()
        // call after creation avoids the ordering issue.
        Schema::table('sales_commission_payouts', function (Blueprint $table) {
            $table->foreign('reverses_payout_id')->references('id')->on('sales_commission_payouts')->restrictOnDelete();
        });

        Schema::create('sales_commission_statement_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('dispute_id')->nullable()->unique()->constrained('sales_commission_disputes')->restrictOnDelete();
            $table->decimal('amount_lkr', 20, 4);
            $table->text('reason');
            $table->foreignUuid('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->string('idempotency_key', 160)->unique();
            $table->timestamps();
        });

        Schema::create('sales_commission_payout_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payout_id')->constrained('sales_commission_payouts')->restrictOnDelete();
            $table->foreignUuid('statement_id')->constrained('sales_commission_statements')->restrictOnDelete();
            $table->decimal('amount_lkr', 20, 4);
            $table->timestamps();
            $table->unique(['payout_id', 'statement_id'], 'commission_payout_statement_unique');
        });

        Schema::create('sales_commission_accounting_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('payout_id')->constrained('sales_commission_payouts')->restrictOnDelete();
            $table->string('event_type', 50);
            $table->string('status', 30);
            $table->string('external_reference', 160)->nullable();
            $table->text('message')->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->foreignUuid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->unique(['payout_id', 'event_type', 'external_reference'], 'commission_accounting_delivery_reference_unique');
        });

        Schema::create('sales_commission_statement_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('statement_id')->constrained('sales_commission_statements')->restrictOnDelete();
            $table->string('format', 10);
            $table->string('disk', 40);
            $table->string('path', 1000);
            $table->string('file_name', 255);
            $table->char('file_checksum', 64);
            $table->unsignedBigInteger('file_size');
            $table->foreignUuid('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('last_downloaded_at')->nullable();
            $table->foreignUuid('last_downloaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 160)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_commission_statement_exports');
        Schema::dropIfExists('sales_commission_accounting_deliveries');
        Schema::dropIfExists('sales_commission_payout_allocations');
        Schema::dropIfExists('sales_commission_statement_adjustments');
        Schema::dropIfExists('sales_commission_payouts');
        Schema::dropIfExists('sales_commission_disputes');
        Schema::dropIfExists('sales_commission_statement_events');
        Schema::dropIfExists('sales_commission_statement_lines');
        Schema::dropIfExists('sales_commission_statements');
    }
};
