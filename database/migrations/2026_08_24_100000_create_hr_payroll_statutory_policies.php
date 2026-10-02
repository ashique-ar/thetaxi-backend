<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_epf_etf_contribution_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->decimal('employee_epf_rate_percent', 5, 2);
            $table->decimal('employer_epf_rate_percent', 5, 2);
            $table->decimal('employer_etf_rate_percent', 5, 2);
            $table->json('earnings_basis');
            $table->string('statutory_reference', 255);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->text('reason');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'version'], 'hr_epf_etf_policy_version_unique');
            $table->index(['company_id', 'status', 'effective_from'], 'hr_epf_etf_policy_effective_idx');
        });
        DB::statement('ALTER TABLE hr_epf_etf_contribution_policies ADD CONSTRAINT hr_epf_etf_policy_checker CHECK (approved_by IS NULL OR created_by <> approved_by)');
        DB::statement('ALTER TABLE hr_epf_etf_contribution_policies ADD CONSTRAINT hr_epf_etf_policy_nonnegative CHECK (employee_epf_rate_percent >= 0 AND employer_epf_rate_percent >= 0 AND employer_etf_rate_percent >= 0)');

        Schema::create('hr_gratuity_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('minimum_qualifying_service_years');
            $table->unsignedSmallInteger('minimum_employer_headcount_threshold');
            $table->decimal('monthly_paid_divisor', 4, 2);
            $table->decimal('non_monthly_daily_wage_multiplier', 5, 2);
            $table->unsignedSmallInteger('non_monthly_lookback_months');
            $table->unsignedSmallInteger('payment_deadline_days');
            $table->decimal('tax_exempt_threshold_lkr', 14, 2)->nullable();
            $table->decimal('tax_rate_above_threshold_percent', 5, 2)->nullable();
            $table->string('statutory_reference', 255);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->text('reason');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'version'], 'hr_gratuity_policy_version_unique');
            $table->index(['company_id', 'status', 'effective_from'], 'hr_gratuity_policy_effective_idx');
        });
        DB::statement('ALTER TABLE hr_gratuity_policies ADD CONSTRAINT hr_gratuity_policy_checker CHECK (approved_by IS NULL OR created_by <> approved_by)');
        DB::statement('ALTER TABLE hr_gratuity_policies ADD CONSTRAINT hr_gratuity_policy_positive CHECK (minimum_qualifying_service_years > 0 AND monthly_paid_divisor > 0 AND non_monthly_daily_wage_multiplier > 0 AND non_monthly_lookback_months > 0 AND payment_deadline_days > 0)');
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_epf_etf_contribution_policies') && DB::table('hr_epf_etf_contribution_policies')->exists()) {
            throw new RuntimeException('Refusing to drop governed EPF/ETF statutory-policy evidence while rows exist.');
        }
        if (Schema::hasTable('hr_gratuity_policies') && DB::table('hr_gratuity_policies')->exists()) {
            throw new RuntimeException('Refusing to drop governed gratuity statutory-policy evidence while rows exist.');
        }

        Schema::dropIfExists('hr_gratuity_policies');
        Schema::dropIfExists('hr_epf_etf_contribution_policies');
    }
};
