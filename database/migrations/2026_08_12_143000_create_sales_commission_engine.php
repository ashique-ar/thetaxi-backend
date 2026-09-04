<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_commission_plan_families', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 160);
            $table->string('commission_category', 30);
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('sales_commission_plan_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_family_id')->constrained('sales_commission_plan_families')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('formula_kind', 30);
            $table->string('eligible_basis', 40)->default('full_eligible_receipt_lkr');
            $table->decimal('percentage_rate', 9, 6)->nullable();
            $table->decimal('fixed_amount_lkr', 20, 4)->nullable();
            $table->string('rounding_mode', 30)->default('half_up');
            $table->unsignedSmallInteger('rounding_scale')->default(2);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['plan_family_id', 'version']);
        });

        Schema::create('sales_commission_plan_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_version_id')->constrained('sales_commission_plan_versions')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->decimal('minimum_lkr', 20, 4);
            $table->decimal('maximum_lkr', 20, 4)->nullable();
            $table->boolean('minimum_inclusive')->default(true);
            $table->boolean('maximum_inclusive')->default(true);
            $table->decimal('percentage_rate', 9, 6);
            $table->timestamps();
            $table->unique(['plan_version_id', 'sequence']);
        });

        Schema::create('sales_commission_plan_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('plan_family_id')->constrained('sales_commission_plan_families')->restrictOnDelete();
            $table->string('scope_type', 30);
            $table->foreignUuid('sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->string('staff_category', 80)->nullable();
            $table->unsignedSmallInteger('precedence');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'scope_type', 'effective_from'], 'commission_assignment_scope_effective_idx');
        });

        Schema::create('sales_commission_staff_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->decimal('percentage_rate', 9, 6);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['staff_id', 'effective_from'], 'commission_staff_override_effective_idx');
        });

        Schema::create('sales_commission_cycle_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 80);
            $table->unsignedInteger('version');
            $table->string('timezone', 80);
            $table->string('earning_period_rule', 80);
            $table->unsignedSmallInteger('cutoff_day');
            $table->unsignedSmallInteger('finalization_day');
            $table->unsignedSmallInteger('approval_deadline_day');
            $table->unsignedSmallInteger('settlement_day');
            $table->string('holiday_rule', 30);
            $table->string('payout_currency', 3)->default('LKR');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code', 'version']);
        });

        Schema::create('sales_commission_cycle_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('cycle_version_id')->constrained('sales_commission_cycle_versions')->restrictOnDelete();
            $table->string('scope_type', 30);
            $table->foreignUuid('sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->string('staff_category', 80)->nullable();
            $table->unsignedSmallInteger('precedence');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'scope_type', 'effective_from'], 'commission_cycle_assignment_effective_idx');
        });

        Schema::table('sales_booking_attributions', function (Blueprint $table) {
            $table->foreignUuid('commission_plan_family_id')->nullable()->constrained('sales_commission_plan_families')->restrictOnDelete();
            $table->foreignUuid('commission_plan_assignment_id')->nullable()->constrained('sales_commission_plan_assignments')->restrictOnDelete();
            $table->timestamp('commission_plan_resolved_at')->nullable();
        });

        Schema::create('sales_commission_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignUuid('root_attribution_id')->nullable()->constrained('sales_booking_attributions')->restrictOnDelete();
            $table->foreignUuid('booking_attribution_id')->nullable()->constrained('sales_booking_attributions')->restrictOnDelete();
            $table->foreignUuid('receipt_id')->constrained('booking_payment_receipts')->restrictOnDelete();
            $table->foreignUuid('receipt_component_id')->unique()->constrained('booking_payment_receipt_components')->restrictOnDelete();
            $table->foreignUuid('beneficiary_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('beneficiary_staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('acquisition_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('collection_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->string('commission_category', 30);
            $table->string('collection_cohort', 30);
            $table->decimal('eligible_source_amount', 20, 4);
            $table->string('source_currency', 3);
            $table->decimal('fx_rate_to_lkr', 20, 10)->nullable();
            $table->timestamp('fx_rate_at')->nullable();
            $table->string('fx_source', 120)->nullable();
            $table->decimal('eligible_lkr_amount', 20, 4)->nullable();
            $table->foreignUuid('plan_family_id')->nullable()->constrained('sales_commission_plan_families')->restrictOnDelete();
            $table->foreignUuid('plan_assignment_id')->nullable()->constrained('sales_commission_plan_assignments')->restrictOnDelete();
            $table->foreignUuid('plan_version_id')->nullable()->constrained('sales_commission_plan_versions')->restrictOnDelete();
            $table->foreignUuid('plan_tier_id')->nullable()->constrained('sales_commission_plan_tiers')->restrictOnDelete();
            $table->foreignUuid('staff_override_id')->nullable()->constrained('sales_commission_staff_overrides')->restrictOnDelete();
            $table->string('formula_kind', 30)->nullable();
            $table->decimal('applied_rate', 9, 6)->nullable();
            $table->decimal('fixed_amount_lkr', 20, 4)->nullable();
            $table->decimal('commission_amount_lkr', 20, 4)->nullable();
            $table->string('status', 30);
            $table->string('hold_code', 80)->nullable();
            $table->text('calculation_explanation');
            $table->string('receipt_finality_status', 30);
            $table->timestamp('earned_at')->nullable();
            $table->timestamp('decision_at');
            $table->unsignedInteger('event_version')->default(1);
            $table->string('idempotency_key', 160)->unique();
            $table->timestamps();
            $table->index(['beneficiary_staff_id', 'status', 'decision_at'], 'commission_decision_staff_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_commission_decisions');
        Schema::table('sales_booking_attributions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_plan_assignment_id');
            $table->dropConstrainedForeignId('commission_plan_family_id');
            $table->dropColumn('commission_plan_resolved_at');
        });
        Schema::dropIfExists('sales_commission_cycle_assignments');
        Schema::dropIfExists('sales_commission_cycle_versions');
        Schema::dropIfExists('sales_commission_staff_overrides');
        Schema::dropIfExists('sales_commission_plan_assignments');
        Schema::dropIfExists('sales_commission_plan_tiers');
        Schema::dropIfExists('sales_commission_plan_versions');
        Schema::dropIfExists('sales_commission_plan_families');
    }
};
