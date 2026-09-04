<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payment_receipts', function (Blueprint $table) {
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->decimal('source_amount', 20, 4)->nullable();
            $table->string('source_currency', 3)->nullable();
            $table->decimal('lkr_amount', 20, 4)->nullable();
            $table->decimal('fx_rate_to_lkr', 20, 10)->nullable();
            $table->timestamp('fx_rate_at')->nullable();
            $table->string('fx_source', 120)->nullable();
            $table->string('finality_status', 30)->default('confirmed')->index();
            $table->timestamp('finalized_at')->nullable();
            $table->string('provider_event_id', 160)->nullable();
            $table->char('provider_payload_checksum', 64)->nullable();
            $table->char('request_payload_checksum', 64)->nullable();
            $table->unsignedInteger('event_version')->default(1);
            $table->unique(['payment_method', 'provider_event_id'], 'booking_receipt_provider_event_unique');
        });

        Schema::create('booking_payment_receipt_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('receipt_id')->constrained('booking_payment_receipts')->restrictOnDelete();
            $table->string('component_type', 40);
            $table->decimal('source_amount', 20, 4);
            $table->decimal('lkr_amount', 20, 4)->nullable();
            $table->boolean('is_allocatable')->default(true);
            $table->boolean('is_collection_target_eligible')->default(false);
            $table->boolean('is_commission_eligible')->default(false);
            $table->decimal('allocated_source_amount', 20, 4)->default(0);
            $table->decimal('adjusted_source_amount', 20, 4)->default(0);
            $table->timestamps();
            $table->unique(['receipt_id', 'component_type']);
        });

        Schema::create('booking_payment_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignUuid('receipt_id')->nullable()->constrained('booking_payment_receipts')->restrictOnDelete();
            $table->foreignUuid('receipt_component_id')->nullable()->constrained('booking_payment_receipt_components')->restrictOnDelete();
            $table->string('impact_dimension', 30);
            $table->string('adjustment_type', 40);
            $table->string('direction', 10);
            $table->decimal('source_amount', 20, 4);
            $table->string('source_currency', 3);
            $table->decimal('lkr_amount', 20, 4)->nullable();
            $table->decimal('fx_rate_to_lkr', 20, 10)->nullable();
            $table->timestamp('adjustment_effective_at');
            $table->text('reason');
            $table->string('reference', 160)->nullable();
            $table->string('idempotency_key', 160);
            $table->char('request_payload_checksum', 64);
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['booking_id', 'idempotency_key'], 'booking_payment_adjustment_idempotency_unique');
            $table->index(['booking_id', 'adjustment_effective_at']);
        });

        Schema::create('booking_payment_finality_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('payment_method', 50);
            $table->string('official_collection_state', 30);
            $table->boolean('can_earn_before_final')->default(false);
            $table->boolean('hold_payout_until_final')->default(true);
            $table->unsignedInteger('clearance_timeout_hours')->nullable();
            $table->string('required_evidence_type', 80)->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'payment_method', 'version'], 'booking_finality_policy_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payment_finality_policies');
        Schema::dropIfExists('booking_payment_adjustments');
        Schema::dropIfExists('booking_payment_receipt_components');
        Schema::table('booking_payment_receipts', function (Blueprint $table) {
            $table->dropUnique('booking_receipt_provider_event_unique');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn([
                'source_amount', 'source_currency', 'lkr_amount', 'fx_rate_to_lkr', 'fx_rate_at', 'fx_source',
                'finality_status', 'finalized_at', 'provider_event_id', 'provider_payload_checksum',
                'request_payload_checksum', 'event_version',
            ]);
        });
    }
};
