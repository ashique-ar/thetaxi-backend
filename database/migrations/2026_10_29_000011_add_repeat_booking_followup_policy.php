<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_policy_settings', function (Blueprint $table): void {
            $table->boolean('repeat_booking_followup_enabled')->nullable();
            $table->unsignedInteger('repeat_booking_followup_days')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') $this->rebuildPolicyConstraint(true);
    }

    public function down(): void
    {
        if (DB::table('sales_policy_settings')->where('policy_kind', 'repeat_booking_followup')->exists()) {
            throw new RuntimeException('Reconcile repeat-booking follow-up policy history before rolling back.');
        }

        if (DB::getDriverName() === 'pgsql') $this->rebuildPolicyConstraint(false);
        Schema::table('sales_policy_settings', function (Blueprint $table): void {
            $table->dropColumn(['repeat_booking_followup_enabled', 'repeat_booking_followup_days']);
        });
    }

    private function rebuildPolicyConstraint(bool $includeRepeatFollowup): void
    {
        $noRepeat = 'repeat_booking_followup_enabled IS NULL AND repeat_booking_followup_days IS NULL';
        $common = 'fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND dispute_response_days IS NULL AND profile_export_retention_days IS NULL AND business_timezone IS NULL AND inquiry_first_response_minutes IS NULL AND booking_assignment_target_minutes IS NULL';
        $repeatNull = $includeRepeatFollowup ? " AND {$noRepeat}" : '';

        $branches = [
            "(policy_kind = 'fx_corrections' AND fx_quote_base IS NOT NULL AND fx_calculation_mode IS NOT NULL AND fx_max_rate_age_hours IS NOT NULL AND fx_rounding_scale IS NOT NULL AND dispute_response_days IS NULL AND profile_export_retention_days IS NULL AND business_timezone IS NULL AND inquiry_first_response_minutes IS NULL AND booking_assignment_target_minutes IS NULL AND inquiry_response_business_hours IS NULL{$repeatNull})",
            "(policy_kind = 'commission_dispute' AND dispute_response_days IS NOT NULL AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND profile_export_retention_days IS NULL AND business_timezone IS NULL AND inquiry_first_response_minutes IS NULL AND booking_assignment_target_minutes IS NULL AND inquiry_response_business_hours IS NULL{$repeatNull})",
            "(policy_kind = 'profile_export_retention' AND profile_export_retention_days IS NOT NULL AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND dispute_response_days IS NULL AND business_timezone IS NULL AND inquiry_first_response_minutes IS NULL AND booking_assignment_target_minutes IS NULL AND inquiry_response_business_hours IS NULL{$repeatNull})",
            "(policy_kind = 'business_timezone' AND business_timezone IS NOT NULL AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND dispute_response_days IS NULL AND profile_export_retention_days IS NULL AND inquiry_first_response_minutes IS NULL AND booking_assignment_target_minutes IS NULL AND inquiry_response_business_hours IS NULL{$repeatNull})",
            "(policy_kind = 'inquiry_response' AND inquiry_first_response_minutes BETWEEN 1 AND 10080 AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND dispute_response_days IS NULL AND profile_export_retention_days IS NULL AND business_timezone IS NULL AND booking_assignment_target_minutes IS NULL{$repeatNull})",
            "(policy_kind = 'booking_assignment' AND booking_assignment_target_minutes BETWEEN 1 AND 10080 AND fx_quote_base IS NULL AND fx_calculation_mode IS NULL AND fx_max_rate_age_hours IS NULL AND fx_rounding_scale IS NULL AND dispute_response_days IS NULL AND profile_export_retention_days IS NULL AND business_timezone IS NULL AND inquiry_first_response_minutes IS NULL AND inquiry_response_business_hours IS NULL{$repeatNull})",
        ];

        if ($includeRepeatFollowup) {
            $branches[] = "(policy_kind = 'repeat_booking_followup' AND repeat_booking_followup_enabled IS NOT NULL AND ((repeat_booking_followup_enabled = true AND repeat_booking_followup_days BETWEEN 1 AND 3650) OR (repeat_booking_followup_enabled = false AND repeat_booking_followup_days IS NULL)) AND {$common} AND inquiry_response_business_hours IS NULL)";
        }

        DB::statement('ALTER TABLE sales_policy_settings DROP CONSTRAINT sales_policy_setting_kind_shape');
        DB::statement('ALTER TABLE sales_policy_settings ADD CONSTRAINT sales_policy_setting_kind_shape CHECK ('.implode(' OR ', $branches).')');
    }
};
