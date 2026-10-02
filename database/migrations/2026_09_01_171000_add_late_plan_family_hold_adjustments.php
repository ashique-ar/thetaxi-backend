<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_commission_hold_adjustments DROP CONSTRAINT IF EXISTS sales_commission_hold_adjustment_kind');
            DB::statement("ALTER TABLE sales_commission_hold_adjustments ADD CONSTRAINT sales_commission_hold_adjustment_kind CHECK ((adjustment_kind = 'late_attribution_entitlement' AND original_hold_code = 'attribution_missing' AND receipt_finality_event_id IS NULL) OR (adjustment_kind = 'late_acquisition_owner_entitlement' AND original_hold_code IN ('acquisition_profile_missing', 'acquisition_profile_ineligible', 'legal_entity_mismatch') AND beneficiary_attribution_event_id IS NOT NULL AND finality_policy_id IS NOT NULL AND receipt_finality_event_id IS NULL) OR (adjustment_kind = 'late_finality_policy_entitlement' AND original_hold_code IN ('finality_policy_missing', 'finality_policy_invalid') AND finality_policy_id IS NOT NULL AND receipt_finality_event_id IS NOT NULL) OR (adjustment_kind = 'late_beneficiary_correction_entitlement' AND original_hold_code IN ('beneficiary_missing', 'collection_handler_ineligible', 'commission_beneficiary_ineligible', 'legal_entity_mismatch') AND beneficiary_attribution_event_id IS NULL AND finality_policy_id IS NOT NULL AND receipt_finality_event_id IS NULL) OR (adjustment_kind = 'late_plan_family_entitlement' AND original_hold_code = 'plan_family_missing' AND beneficiary_attribution_event_id IS NOT NULL AND finality_policy_id IS NOT NULL AND receipt_finality_event_id IS NULL))");
        }
    }

    public function down(): void
    {
        if (DB::table('sales_commission_hold_adjustments')->where('adjustment_kind', 'late_plan_family_entitlement')->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile late plan-family commission entitlement evidence first.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_commission_hold_adjustments DROP CONSTRAINT IF EXISTS sales_commission_hold_adjustment_kind');
            DB::statement("ALTER TABLE sales_commission_hold_adjustments ADD CONSTRAINT sales_commission_hold_adjustment_kind CHECK ((adjustment_kind = 'late_attribution_entitlement' AND original_hold_code = 'attribution_missing' AND receipt_finality_event_id IS NULL) OR (adjustment_kind = 'late_acquisition_owner_entitlement' AND original_hold_code IN ('acquisition_profile_missing', 'acquisition_profile_ineligible', 'legal_entity_mismatch') AND beneficiary_attribution_event_id IS NOT NULL AND finality_policy_id IS NOT NULL AND receipt_finality_event_id IS NULL) OR (adjustment_kind = 'late_finality_policy_entitlement' AND original_hold_code IN ('finality_policy_missing', 'finality_policy_invalid') AND finality_policy_id IS NOT NULL AND receipt_finality_event_id IS NOT NULL) OR (adjustment_kind = 'late_beneficiary_correction_entitlement' AND original_hold_code IN ('beneficiary_missing', 'collection_handler_ineligible', 'commission_beneficiary_ineligible', 'legal_entity_mismatch') AND beneficiary_attribution_event_id IS NULL AND finality_policy_id IS NOT NULL AND receipt_finality_event_id IS NULL))");
        }
    }
};
