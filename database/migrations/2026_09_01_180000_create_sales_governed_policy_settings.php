<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_policy_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('policy_kind', 40);
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            // fx_corrections
            $table->string('fx_quote_base', 40)->nullable();
            $table->string('fx_calculation_mode', 40)->nullable();
            $table->unsignedInteger('fx_max_rate_age_hours')->nullable();
            $table->unsignedSmallInteger('fx_rounding_scale')->nullable();
            // commission_dispute
            $table->unsignedInteger('dispute_response_days')->nullable();
            // profile_export_retention
            $table->unsignedInteger('profile_export_retention_days')->nullable();
            // business_timezone
            $table->string('business_timezone', 80)->nullable();
            $table->text('reason')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'policy_kind', 'version'], 'sales_policy_setting_company_kind_version_unique');
            $table->index(['company_id', 'policy_kind', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sales_policy_settings ADD CONSTRAINT sales_policy_setting_kind_shape CHECK ("
                . "(policy_kind = 'fx_corrections' AND fx_quote_base IS NOT NULL AND fx_calculation_mode IS NOT NULL AND fx_max_rate_age_hours IS NOT NULL AND fx_rounding_scale IS NOT NULL AND dispute_response_days IS NULL AND profile_export_retention_days IS NULL AND business_timezone IS NULL) "
                . "OR (policy_kind = 'commission_dispute' AND dispute_response_days IS NOT NULL AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND profile_export_retention_days IS NULL AND business_timezone IS NULL) "
                . "OR (policy_kind = 'profile_export_retention' AND profile_export_retention_days IS NOT NULL AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND dispute_response_days IS NULL AND business_timezone IS NULL) "
                . "OR (policy_kind = 'business_timezone' AND business_timezone IS NOT NULL AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND dispute_response_days IS NULL AND profile_export_retention_days IS NULL)"
                . ")");
        }

        Schema::create('sales_staff_category_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('category_name', 80);
            $table->string('status', 20)->default('draft');
            $table->text('reason')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('retired_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'category_name'], 'sales_staff_category_company_name_unique');
            $table->index(['company_id', 'status']);
        });

        Schema::create('sales_company_feature_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('feature_key', 80);
            $table->unsignedInteger('version');
            $table->boolean('enabled');
            $table->string('status', 20)->default('draft');
            $table->text('reason');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'feature_key', 'version'], 'sales_company_feature_company_key_version_unique');
            $table->index(['company_id', 'feature_key', 'status']);
        });
    }

    public function down(): void
    {
        if (
            DB::table('sales_policy_settings')->whereNotNull('approved_by')->exists()
            || DB::table('sales_staff_category_definitions')->whereNotNull('approved_by')->exists()
            || DB::table('sales_company_feature_settings')->whereNotNull('approved_by')->exists()
        ) {
            throw new RuntimeException('Rollback refused: export and reconcile approved Sales governed policy-setting and staff-category evidence first.');
        }
        Schema::dropIfExists('sales_company_feature_settings');
        Schema::dropIfExists('sales_staff_category_definitions');
        Schema::dropIfExists('sales_policy_settings');
    }
};
