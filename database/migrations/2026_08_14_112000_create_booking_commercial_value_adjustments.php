<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_commercial_value_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignUuid('attribution_id')->constrained('sales_booking_attributions')->restrictOnDelete();
            // Frozen at write time: the acquisition owner never moves because of a value change.
            $table->foreignUuid('acquisition_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->string('adjustment_type', 30);
            $table->decimal('delta_source_amount', 20, 4);
            $table->string('source_currency', 3);
            $table->decimal('delta_lkr_amount', 20, 4)->nullable();
            $table->decimal('fx_rate_to_lkr', 20, 10)->nullable();
            $table->timestamp('fx_rate_at')->nullable();
            $table->decimal('previous_effective_source_amount', 20, 4);
            $table->decimal('previous_effective_lkr_amount', 20, 4)->nullable();
            $table->decimal('resulting_effective_source_amount', 20, 4);
            $table->decimal('resulting_effective_lkr_amount', 20, 4)->nullable();
            $table->boolean('counts_as_new_sales_adjustment')->default(false);
            $table->foreignUuid('schedule_revision_id')->nullable()->constrained('booking_payment_schedule_revisions')->nullOnDelete();
            $table->timestamp('effective_at');
            $table->text('reason');
            $table->string('idempotency_key', 160);
            $table->char('request_payload_checksum', 64);
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['booking_id', 'idempotency_key'], 'booking_commercial_value_adjustment_idempotency_unique');
            $table->index(['booking_id', 'effective_at']);
            $table->index(['acquisition_sales_profile_id', 'effective_at'], 'commercial_value_adjustment_acquisition_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_commercial_value_adjustments');
    }
};
