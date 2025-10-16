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
        Schema::table('vehicle_models', function (Blueprint $table) {
            // Drop the incorrect unique constraint on make_id
            $table->dropUnique(['make_id']);
            
            // Add correct composite unique constraint on make_id and name
            $table->unique(['make_id', 'name'], 'vehicle_models_make_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_models', function (Blueprint $table) {
            // Drop the composite unique constraint
            $table->dropUnique('vehicle_models_make_name_unique');
            
            // Restore the original unique constraint (though it was incorrect)
            $table->unique('make_id');
        });
    }
};
