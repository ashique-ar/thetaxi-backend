<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_transactions', 'company_id')) {
                $table->uuid('company_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_transactions', 'company_id')) {
            if (DB::table('payment_transactions')->whereNotNull('company_id')->exists()) {
                throw new RuntimeException('Cannot remove payment-attempt company snapshots after payment evidence exists.');
            }

            Schema::table('payment_transactions', fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }
};
