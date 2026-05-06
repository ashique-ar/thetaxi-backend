<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vehicle_pricing_history', function (Blueprint $table) {
            $table->uuid('service_type_id')->nullable()->change();
            $table->uuid('changed_by')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_pricing_history', function (Blueprint $table) {
            $table->uuid('service_type_id')->nullable(false)->change();
            $table->uuid('changed_by')->nullable(false)->change();
        });
    }
};
