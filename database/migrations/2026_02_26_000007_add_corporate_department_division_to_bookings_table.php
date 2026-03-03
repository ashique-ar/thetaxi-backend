<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->uuid('corporate_department_id')->nullable()->after('corporate_account_id');
            $table->uuid('corporate_division_id')->nullable()->after('corporate_department_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['corporate_department_id']);
            $table->dropForeign(['corporate_division_id']);
            $table->dropColumn(['corporate_department_id', 'corporate_division_id']);
        });
    }
};
