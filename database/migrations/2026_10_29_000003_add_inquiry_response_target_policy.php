<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_policy_settings', function (Blueprint $table) {
            $table->unsignedInteger('inquiry_first_response_minutes')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_policy_settings DROP CONSTRAINT sales_policy_setting_kind_shape');
            DB::statement("ALTER TABLE sales_policy_settings ADD CONSTRAINT sales_policy_setting_kind_shape CHECK (
                (policy_kind = 'fx_corrections' AND fx_quote_base IS NOT NULL AND fx_calculation_mode IS NOT NULL AND fx_max_rate_age_hours IS NOT NULL AND fx_rounding_scale IS NOT NULL AND dispute_response_days IS NULL AND profile_export_retention_days IS NULL AND business_timezone IS NULL AND inquiry_first_response_minutes IS NULL)
                OR (policy_kind = 'commission_dispute' AND dispute_response_days IS NOT NULL AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND profile_export_retention_days IS NULL AND business_timezone IS NULL AND inquiry_first_response_minutes IS NULL)
                OR (policy_kind = 'profile_export_retention' AND profile_export_retention_days IS NOT NULL AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND dispute_response_days IS NULL AND business_timezone IS NULL AND inquiry_first_response_minutes IS NULL)
                OR (policy_kind = 'business_timezone' AND business_timezone IS NOT NULL AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND dispute_response_days IS NULL AND profile_export_retention_days IS NULL AND inquiry_first_response_minutes IS NULL)
                OR (policy_kind = 'inquiry_response' AND inquiry_first_response_minutes BETWEEN 1 AND 10080 AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND dispute_response_days IS NULL AND profile_export_retention_days IS NULL AND business_timezone IS NULL)
            )");
        }
    }

    public function down(): void
    {
        if (DB::table('sales_policy_settings')->where('policy_kind', 'inquiry_response')->exists()) {
            throw new RuntimeException('Retire inquiry-response policy rows before rolling back their schema.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_policy_settings DROP CONSTRAINT sales_policy_setting_kind_shape');
            DB::statement("ALTER TABLE sales_policy_settings ADD CONSTRAINT sales_policy_setting_kind_shape CHECK (
                (policy_kind = 'fx_corrections' AND fx_quote_base IS NOT NULL AND fx_calculation_mode IS NOT NULL AND fx_max_rate_age_hours IS NOT NULL AND fx_rounding_scale IS NOT NULL AND dispute_response_days IS NULL AND profile_export_retention_days IS NULL AND business_timezone IS NULL)
                OR (policy_kind = 'commission_dispute' AND dispute_response_days IS NOT NULL AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND profile_export_retention_days IS NULL AND business_timezone IS NULL)
                OR (policy_kind = 'profile_export_retention' AND profile_export_retention_days IS NOT NULL AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND dispute_response_days IS NULL AND business_timezone IS NULL)
                OR (policy_kind = 'business_timezone' AND business_timezone IS NOT NULL AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND dispute_response_days IS NULL AND profile_export_retention_days IS NULL)
            )");
        }

        Schema::table('sales_policy_settings', function (Blueprint $table) {
            $table->dropColumn('inquiry_first_response_minutes');
        });
    }
};
