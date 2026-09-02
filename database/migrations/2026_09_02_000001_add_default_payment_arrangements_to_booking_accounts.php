<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('default_payment_arrangement')->default('cash_to_driver')->after('marketing_consent_ip');
        });

        Schema::table('corporates', function (Blueprint $table) {
            $table->string('default_payment_arrangement')->default('monthly_invoice')->after('coordinator_can_view_payments');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('default_payment_arrangement'));
        Schema::table('corporates', fn (Blueprint $table) => $table->dropColumn('default_payment_arrangement'));
    }
};
