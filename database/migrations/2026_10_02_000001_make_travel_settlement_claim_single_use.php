<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('hr_travel_requests')->whereNotNull('settlement_claim_id')
            ->select('settlement_claim_id')->groupBy('settlement_claim_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Resolve duplicate travel settlement claim links before applying this migration.');
        }

        Schema::table('hr_travel_requests', function (Blueprint $table) {
            $table->unique('settlement_claim_id', 'hr_travel_settlement_claim_unique');
        });
    }

    public function down(): void
    {
        Schema::table('hr_travel_requests', function (Blueprint $table) {
            $table->dropUnique('hr_travel_settlement_claim_unique');
        });
    }
};
