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
        Schema::table('vehicle_addons', function (Blueprint $table) {
            $table->enum('billing_type', ['per_package', 'per_day', 'per_hour'])->default('per_day')->after('rate_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_addons', function (Blueprint $table) {
            $table->dropColumn('billing_type');
        });
    }
};
