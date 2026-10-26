<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $columns = [
        'booking_searches' => ['created_user_id', 'updated_user_id'],
        'carts' => ['created_user_id', 'updated_user_id'],
        'payment_events' => ['updated_user_id'],
        'promo_code_usages' => ['created_user_id', 'updated_user_id'],
        'booking_collection_submissions' => ['created_user_id', 'updated_user_id'],
        'booking_collection_work_items' => ['created_user_id', 'updated_user_id'],
        'booking_commercial_value_adjustments' => ['updated_user_id'],
        'booking_common_rate_pricings' => ['created_user_id', 'updated_user_id'],
        'booking_deposit_refunds' => ['created_user_id', 'updated_user_id'],
        'booking_payment_adjustments' => ['updated_user_id'],
        'booking_payment_receipt_finality_events' => ['created_user_id', 'updated_user_id'],
        'booking_pricings' => ['created_user_id', 'updated_user_id'],
        'booking_terms' => ['created_user_id', 'updated_user_id'],
        'corporate_contract_locations' => ['created_user_id', 'updated_user_id'],
        'driver_onboarding_applications' => ['created_user_id', 'updated_user_id'],
        'driver_cash_settlements' => ['updated_user_id'],
        'driver_cash_settlement_items' => ['created_user_id', 'updated_user_id'],
        'financial_adjustments' => ['updated_user_id'],
        'financial_payment_allocations' => ['created_user_id', 'updated_user_id'],
        'financial_settlement_documents' => ['created_user_id', 'updated_user_id'],
        'financial_settlement_items' => ['created_user_id', 'updated_user_id'],
        'sales_company_feature_settings' => ['created_user_id', 'updated_user_id'],
        'sales_policy_settings' => ['created_user_id', 'updated_user_id'],
        'sales_staff_category_definitions' => ['created_user_id', 'updated_user_id'],
        'faqs' => ['created_user_id', 'updated_user_id'],
        'faq_categories' => ['created_user_id', 'updated_user_id'],
        'booking_payment_receipts' => ['created_user_id', 'updated_user_id'],
        'booking_payment_receipt_components' => ['created_user_id', 'updated_user_id'],
        'customer_devices' => ['created_user_id', 'updated_user_id'],
        'booking_customer_mobile_activities' => ['created_user_id', 'updated_user_id'],
        'booking_items' => ['created_user_id', 'updated_user_id'],
        'booking_payment_finality_policies' => ['created_user_id', 'updated_user_id'],
        'booking_payment_schedule_revisions' => ['created_user_id', 'updated_user_id'],
        'booking_payment_schedule_rules' => ['created_user_id', 'updated_user_id'],
        'booking_payment_schedule_rule_events' => ['created_user_id', 'updated_user_id'],
        'corporate_report_schedules' => ['created_user_id', 'updated_user_id'],
        'driver_devices' => ['created_user_id', 'updated_user_id'],
        'driver_mobile_diagnostic_reports' => ['created_user_id', 'updated_user_id'],
        'corporate_online_payment_attempts' => ['created_user_id', 'updated_user_id'],
        'corporate_remittances' => ['created_user_id', 'updated_user_id'],
        'corporate_remittance_allocations' => ['created_user_id', 'updated_user_id'],
        'financial_allocation_reversals' => ['created_user_id', 'updated_user_id'],
        'hr_epf_etf_contribution_policies' => ['created_user_id', 'updated_user_id'],
        'hr_gratuity_policies' => ['created_user_id', 'updated_user_id'],
        'sales_commission_hold_adjustments' => ['created_user_id', 'updated_user_id'],
        'sales_commission_hold_releases' => ['created_user_id', 'updated_user_id'],
        'sales_commission_hold_resolutions' => ['created_user_id', 'updated_user_id'],
        'sales_target_copy_batches' => ['created_user_id', 'updated_user_id'],
        'shortened_urls' => ['created_user_id', 'updated_user_id'],
        'short_url_clicks' => ['created_user_id', 'updated_user_id'],
        'booking_price_adjustment_history' => ['created_user_id', 'updated_user_id'],
    ];

    public function up(): void
    {
        foreach ($this->columns as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $missing = array_values(array_filter($columns, fn (string $columnName): bool => ! Schema::hasColumn($tableName, $columnName)));
            if ($missing) {
                Schema::table($tableName, function (Blueprint $table) use ($missing): void {
                    foreach ($missing as $columnName) {
                        $table->uuid($columnName)->nullable()->index();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        $existingColumns = [];
        foreach ($this->columns as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($columns as $columnName) {
                if (! Schema::hasColumn($tableName, $columnName)) {
                    continue;
                }
                if (DB::table($tableName)->whereNotNull($columnName)->exists()) {
                    throw new LogicException("Cannot remove populated {$tableName}.{$columnName} tracking data.");
                }
                $existingColumns[$tableName][] = $columnName;
            }
        }

        foreach ($existingColumns as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
