<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_commission_hold_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('commission_decision_id')->unique()->constrained('sales_commission_decisions')->restrictOnDelete();
            $table->string('release_kind', 40)->default('manual_formula');
            $table->foreignUuid('receipt_finality_event_id')->nullable()
                ->constrained('booking_payment_receipt_finality_events')->restrictOnDelete();
            $table->foreignUuid('beneficiary_sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('beneficiary_staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('original_hold_code', 80);
            $table->foreignUuid('plan_version_id')->nullable()->constrained('sales_commission_plan_versions')->restrictOnDelete();
            $table->foreignUuid('plan_tier_id')->nullable()->constrained('sales_commission_plan_tiers')->restrictOnDelete();
            $table->foreignUuid('staff_override_id')->nullable()->constrained('sales_commission_staff_overrides')->restrictOnDelete();
            $table->string('formula_kind', 30);
            $table->decimal('applied_rate', 9, 6)->nullable();
            $table->decimal('fixed_amount_lkr', 20, 4)->nullable();
            $table->decimal('commission_amount_lkr', 20, 4);
            $table->text('calculation_explanation');
            $table->json('frozen_calculation_snapshot');
            $table->char('calculation_checksum', 64);
            $table->text('release_reason');
            $table->foreignUuid('released_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('released_at');
            $table->string('idempotency_key', 160)->unique();
            $table->char('request_payload_checksum', 64);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'beneficiary_staff_id', 'released_at'], 'commission_hold_release_statement_idx');
            $table->index('receipt_finality_event_id', 'commission_hold_release_finality_event_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_commission_hold_releases') && DB::table('sales_commission_hold_releases')->exists()) {
            throw new RuntimeException('Refusing to drop immutable commission hold release evidence while rows exist.');
        }

        Schema::dropIfExists('sales_commission_hold_releases');
    }
};
