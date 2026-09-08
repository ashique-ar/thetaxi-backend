<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::table('customers')->update(['default_payment_arrangement' => 'account_credit']);
        DB::table('corporates')->update(['default_payment_arrangement' => 'monthly_invoice']);

        Schema::table('customers', function (Blueprint $table) {
            $table->string('default_payment_arrangement')->default('account_credit')->change();
        });
        Schema::table('corporates', function (Blueprint $table) {
            $table->string('default_payment_arrangement')->default('monthly_invoice')->change();
        });
    }

    public function down(): void
    {
        DB::table('customers')->update(['default_payment_arrangement' => 'cash_to_driver']);
        DB::table('corporates')->update(['default_payment_arrangement' => 'monthly_invoice']);

        Schema::table('customers', function (Blueprint $table) {
            $table->string('default_payment_arrangement')->default('cash_to_driver')->change();
        });
        Schema::table('corporates', function (Blueprint $table) {
            $table->string('default_payment_arrangement')->default('monthly_invoice')->change();
        });
    }
};
