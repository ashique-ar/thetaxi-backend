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
        Schema::create('corporate_vehicle_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('corporate_id');
            $table->uuid('vehicle_group_id');
            $table->timestamps();
            $table->softDeletes();
            // Unique composite index
            $table->unique(['corporate_id', 'vehicle_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corporate_vehicle_groups');
    }
};
