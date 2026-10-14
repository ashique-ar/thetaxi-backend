<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_transactions', 'requested_amount')) {
                $table->decimal('requested_amount', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('payment_transactions', 'requested_currency')) {
                $table->string('requested_currency', 3)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('payment_transactions', 'requested_currency')) {
                $table->dropColumn('requested_currency');
            }
            if (Schema::hasColumn('payment_transactions', 'requested_amount')) {
                $table->dropColumn('requested_amount');
            }
        });
    }
};
