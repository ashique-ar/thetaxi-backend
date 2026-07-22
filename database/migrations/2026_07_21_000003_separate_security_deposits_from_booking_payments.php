<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_payment_receipts', function (Blueprint $table) {
            $table->string('payment_purpose')->default('booking_payment')->index()->after('payment_stage');
            $table->decimal('refunded_amount', 12, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('booking_payment_receipts', fn (Blueprint $table) =>
            $table->dropColumn(['payment_purpose', 'refunded_amount'])
        );
    }
};
