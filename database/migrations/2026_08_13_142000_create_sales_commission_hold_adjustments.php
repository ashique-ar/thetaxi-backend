<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_commission_hold_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('commission_decision_id')->unique()->constrained('sales_commission_decisions')->restrictOnDelete();
            $table->foreignUuid('source_attribution_id')->constrained('sales_booking_attributions')->restrictOnDelete();
            $table->foreignUuid('source_attribution_event_id')->constrained('sales_booking_attribution_events')->restrictOnDelete();
            $table->string('adjustment_kind', 50);
            $table->string('original_hold_code', 80);
            $table->foreignUuid('beneficiary_sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('beneficiary_staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('plan_family_id')->constrained('sales_commission_plan_families')->restrictOnDelete();
            $table->foreignUuid('plan_assignment_id')->nullable()->constrained('sales_commission_plan_assignments')->restrictOnDelete();
            $table->foreignUuid('plan_version_id')->nullable()->constrained('sales_commission_plan_versions')->restrictOnDelete();
            $table->foreignUuid('plan_tier_id')->nullable()->constrained('sales_commission_plan_tiers')->restrictOnDelete();
            $table->foreignUuid('staff_override_id')->nullable()->constrained('sales_commission_staff_overrides')->restrictOnDelete();
            $table->string('formula_kind', 30);
            $table->decimal('applied_rate', 9, 6)->nullable();
            $table->decimal('fixed_amount_lkr', 20, 4)->nullable();
            $table->decimal('eligible_lkr_amount', 20, 4);
            $table->decimal('commission_adjustment_lkr', 20, 4);
            $table->json('frozen_adjustment_snapshot');
            $table->char('calculation_checksum', 64);
            $table->text('reason');
            $table->foreignUuid('source_prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('adjustment_effective_at');
            $table->string('idempotency_key', 160)->unique();
            $table->char('request_payload_checksum', 64);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'beneficiary_staff_id', 'adjustment_effective_at'], 'sales_commission_hold_adjustment_statement_idx');
        });
        DB::statement("ALTER TABLE sales_commission_hold_adjustments ADD CONSTRAINT sales_commission_hold_adjustment_kind CHECK (adjustment_kind = 'late_attribution_entitlement' AND original_hold_code = 'attribution_missing')");
        DB::statement('ALTER TABLE sales_commission_hold_adjustments ADD CONSTRAINT sales_commission_hold_adjustment_positive CHECK (eligible_lkr_amount > 0 AND commission_adjustment_lkr > 0)');
        DB::statement('ALTER TABLE sales_commission_hold_adjustments ADD CONSTRAINT sales_commission_hold_adjustment_checker CHECK (source_prepared_by <> approved_by)');
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_commission_hold_adjustments') && DB::table('sales_commission_hold_adjustments')->exists()) {
            throw new RuntimeException('Refusing to drop linked commission hold adjustment evidence while rows exist.');
        }
        Schema::dropIfExists('sales_commission_hold_adjustments');
    }
};
