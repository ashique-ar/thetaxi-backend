<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_payment_receipts', fn(Blueprint $table) => $table->uuid('corporate_remittance_id')->nullable()->index()->after('payer_id'));

    }

    public function down(): void
    {
        Schema::table('booking_payment_receipts', fn(Blueprint $table) => $table->dropColumn('corporate_remittance_id'));
    }
};
