<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_lease_releases', function (Blueprint $table) {
            $table->string('settlement_direction', 40)->nullable()->after('settlement_status');
            $table->decimal('settlement_amount', 14, 2)->nullable()->after('settlement_direction');
            $table->string('settlement_method', 40)->nullable()->after('settlement_amount');
            $table->uuid('settlement_idempotency_key')->nullable()->unique()->after('settlement_reference');
        });

        DB::table('vehicle_lease_releases')
            ->where('settlement_status', 'settled')
            ->update([
                'settlement_direction' => DB::raw("
                    CASE
                        WHEN net_settlement_amount > 0 THEN 'payable_to_provider'
                        WHEN net_settlement_amount < 0 THEN 'receivable_from_provider'
                        ELSE 'none'
                    END
                "),
                'settlement_amount' => DB::raw('ABS(net_settlement_amount)'),
                'settlement_method' => DB::raw("COALESCE(settlement_method, 'legacy_unverified')"),
                'settlement_reference' => DB::raw("COALESCE(settlement_reference, 'LEGACY-' || id)"),
                'settlement_idempotency_key' => DB::raw('id'),
                'settled_at' => DB::raw('COALESCE(settled_at, effective_at, updated_at, CURRENT_TIMESTAMP)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('vehicle_lease_releases', function (Blueprint $table) {
            $table->dropUnique(['settlement_idempotency_key']);
            $table->dropColumn([
                'settlement_direction',
                'settlement_amount',
                'settlement_method',
                'settlement_idempotency_key',
            ]);
        });
    }
};
