<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_kpi_snapshot_rows', function (Blueprint $table) {
            $table->decimal('gross_new_sales_lkr', 20, 4)->nullable();
            $table->decimal('new_sales_adjustment_lkr', 20, 4)->nullable();
            $table->decimal('net_new_sales_lkr', 20, 4)->nullable();
            $table->string('new_sales_value_state', 30)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('sales_kpi_snapshot_rows')->whereNotNull('new_sales_value_state')->exists()) {
            throw new RuntimeException('Net New Sales snapshot evidence exists; disable the feature instead of deleting frozen ranking history.');
        }

        Schema::table('sales_kpi_snapshot_rows', function (Blueprint $table) {
            $table->dropColumn([
                'gross_new_sales_lkr', 'new_sales_adjustment_lkr',
                'net_new_sales_lkr', 'new_sales_value_state',
            ]);
        });
    }
};
