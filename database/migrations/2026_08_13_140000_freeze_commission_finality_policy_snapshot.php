<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_commission_decisions', function (Blueprint $table) {
            $table->foreignUuid('finality_policy_id')->nullable()->after('receipt_finality_status')
                ->constrained('booking_payment_finality_policies')->restrictOnDelete();
            $table->boolean('can_earn_before_final_snapshot')->nullable()->after('finality_policy_id');
            $table->boolean('hold_payout_until_final_snapshot')->nullable()->after('can_earn_before_final_snapshot');
            $table->string('rounding_mode_snapshot', 30)->nullable()->after('hold_payout_until_final_snapshot');
            $table->unsignedSmallInteger('rounding_scale_snapshot')->nullable()->after('rounding_mode_snapshot');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_commission_decisions') && DB::table('sales_commission_decisions')
            ->where(function ($query) {
                $query->whereNotNull('finality_policy_id')
                    ->orWhereNotNull('can_earn_before_final_snapshot')
                    ->orWhereNotNull('hold_payout_until_final_snapshot')
                    ->orWhereNotNull('rounding_mode_snapshot')
                    ->orWhereNotNull('rounding_scale_snapshot');
            })->exists()) {
            throw new RuntimeException('Refusing to discard frozen commission finality-policy evidence while decisions reference it.');
        }

        Schema::table('sales_commission_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finality_policy_id');
            $table->dropColumn([
                'can_earn_before_final_snapshot', 'hold_payout_until_final_snapshot',
                'rounding_mode_snapshot', 'rounding_scale_snapshot',
            ]);
        });
    }
};
