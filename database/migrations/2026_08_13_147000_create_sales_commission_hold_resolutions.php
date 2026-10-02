<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_commission_hold_resolutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('commission_decision_id')->unique()
                ->constrained('sales_commission_decisions')->restrictOnDelete();
            $table->foreignUuid('receipt_finality_event_id')
                ->constrained('booking_payment_receipt_finality_events')->restrictOnDelete();
            $table->string('resolution_kind', 50);
            $table->string('original_hold_code', 80);
            $table->json('frozen_resolution_snapshot');
            $table->char('resolution_checksum', 64);
            $table->text('resolution_reason');
            $table->foreignUuid('resolved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at');
            $table->string('idempotency_key', 160)->unique();
            $table->char('request_payload_checksum', 64);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'resolved_at'], 'commission_hold_resolution_company_idx');
            $table->index('receipt_finality_event_id', 'commission_hold_resolution_finality_event_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sales_commission_hold_resolutions ADD CONSTRAINT sales_commission_hold_resolution_kind CHECK (resolution_kind = 'failed_finality_no_entitlement')");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_commission_hold_resolutions')
            && DB::table('sales_commission_hold_resolutions')->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile immutable failed-finality commission hold resolutions first.');
        }

        Schema::dropIfExists('sales_commission_hold_resolutions');
    }
};
