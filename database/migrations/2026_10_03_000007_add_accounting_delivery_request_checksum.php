<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_commission_accounting_deliveries', function (Blueprint $table) {
            $table->char('request_payload_checksum', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_commission_accounting_deliveries', function (Blueprint $table) {
            $table->dropColumn('request_payload_checksum');
        });
    }
};
