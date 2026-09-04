<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_collection_commissions', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropForeign(['booking_payment_receipt_id']);
            $table->dropForeign(['paid_by']);
            $table->dropForeign(['payout_id']);
        });

        Schema::table('booking_collection_commissions', function (Blueprint $table) {
            $table->foreign('booking_id')->references('id')->on('bookings')->restrictOnDelete();
            $table->foreign('booking_payment_receipt_id')->references('id')->on('booking_payment_receipts')->restrictOnDelete();
            $table->foreign('paid_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('payout_id')->references('id')->on('collection_commission_payouts')->restrictOnDelete();
            $table->foreignUuid('canonical_decision_id')->nullable()->unique()
                ->constrained('sales_commission_decisions')->restrictOnDelete();
            $table->string('projection_status', 30)->default('legacy_only');
            $table->timestamp('projected_at')->nullable();
            $table->foreignUuid('projected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('projection_note')->nullable();
        });

        Schema::table('collection_commission_payouts', function (Blueprint $table) {
            $table->dropForeign(['paid_by']);
            $table->timestamp('paid_at')->nullable()->change();
            $table->string('payment_reference')->nullable()->change();
            $table->uuid('paid_by')->nullable()->change();
            $table->string('status', 30)->default('prepared')->change();
        });

        Schema::table('collection_commission_payouts', function (Blueprint $table) {
            $table->foreign('paid_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreignUuid('canonical_payout_id')->nullable()->unique()
                ->constrained('sales_commission_payouts')->restrictOnDelete();
            $table->string('projection_status', 30)->default('legacy_only');
            $table->timestamp('projected_at')->nullable();
            $table->foreignUuid('projected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('projection_note')->nullable();
        });
    }

    public function down(): void
    {
        $unsafePayouts = DB::table('collection_commission_payouts')
            ->whereNull('paid_at')
            ->orWhereNull('paid_by')
            ->orWhereNull('payment_reference')
            ->exists();

        if ($unsafePayouts) {
            throw new RuntimeException('Cannot restore the legacy immediate-paid schema while prepared or incomplete payouts exist.');
        }

        Schema::table('collection_commission_payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('canonical_payout_id');
            $table->dropConstrainedForeignId('projected_by');
            $table->dropColumn(['projection_status', 'projected_at', 'projection_note']);
            $table->dropForeign(['paid_by']);
            $table->timestamp('paid_at')->nullable(false)->change();
            $table->string('payment_reference')->nullable(false)->change();
            $table->uuid('paid_by')->nullable(false)->change();
            $table->string('status', 30)->default('paid')->change();
        });

        Schema::table('collection_commission_payouts', function (Blueprint $table) {
            $table->foreign('paid_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('booking_collection_commissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('canonical_decision_id');
            $table->dropConstrainedForeignId('projected_by');
            $table->dropColumn(['projection_status', 'projected_at', 'projection_note']);
            $table->dropForeign(['booking_id']);
            $table->dropForeign(['booking_payment_receipt_id']);
            $table->dropForeign(['paid_by']);
            $table->dropForeign(['payout_id']);
        });

        Schema::table('booking_collection_commissions', function (Blueprint $table) {
            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
            $table->foreign('booking_payment_receipt_id')->references('id')->on('booking_payment_receipts')->cascadeOnDelete();
            $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('payout_id')->references('id')->on('collection_commission_payouts')->nullOnDelete();
        });
    }
};
