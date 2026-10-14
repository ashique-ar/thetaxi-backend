<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_commission_accounting_deliveries', function (Blueprint $table) {
            $table->string('verification_source', 30)->default('manual');
            $table->foreignUuid('source_outbox_event_id')->nullable()->constrained('domain_outbox_events')->restrictOnDelete();
            $table->string('provider_transaction_id', 160)->nullable();
            $table->char('provider_response_checksum', 64)->nullable();
            $table->dropForeign(['recorded_by']);
            $table->uuid('recorded_by')->nullable()->change();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['company_id', 'provider_transaction_id'], 'commission_delivery_provider_tx_unique');
        });

        DB::table('sales_commission_payouts')->where('accounting_status', 'delivered')
            ->update(['accounting_status' => 'pending_verification']);
        DB::table('sales_commission_payouts')->where('accounting_status', 'pending_verification')
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('sales_commission_accounting_deliveries as delivery')
                    ->whereColumn('delivery.payout_id', 'sales_commission_payouts.id')
                    ->where('delivery.status', 'accepted')->where('delivery.verification_source', 'manual');
            })->update(['accounting_status' => 'manual_acknowledged']);
    }

    public function down(): void
    {
        if (DB::table('sales_commission_accounting_deliveries')->where('verification_source', 'quickbooks')
            ->orWhereNotNull('source_outbox_event_id')->orWhereNotNull('provider_transaction_id')
            ->orWhereNotNull('provider_response_checksum')->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile provider-verified commission accounting evidence first.');
        }
        if (DB::table('sales_commission_payouts')->whereIn('accounting_status', ['manual_acknowledged', 'pending_verification'])->exists()) {
            throw new RuntimeException('Rollback refused: resolve unverified commission delivery states before rollback.');
        }
        if (DB::table('sales_commission_accounting_deliveries')->whereNull('recorded_by')->exists()) {
            throw new RuntimeException('Rollback refused: restore attribution for system-recorded commission accounting events first.');
        }

        Schema::table('sales_commission_accounting_deliveries', function (Blueprint $table) {
            $table->dropUnique('commission_delivery_provider_tx_unique');
            $table->dropConstrainedForeignId('source_outbox_event_id');
            $table->dropColumn(['verification_source', 'provider_transaction_id', 'provider_response_checksum']);
            $table->dropForeign(['recorded_by']);
            $table->uuid('recorded_by')->nullable(false)->change();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
        });
    }
};
