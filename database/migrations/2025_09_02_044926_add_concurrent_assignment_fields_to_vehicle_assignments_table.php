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
        Schema::table('vehicle_assignments', function (Blueprint $table) {
            // Add fields for enhanced assignment management if they don't exist
            if (!Schema::hasColumn('vehicle_assignments', 'maintenance_window')) {
                $table->json('maintenance_window')->nullable()->comment('Maintenance window details');
            }
            
            if (!Schema::hasColumn('vehicle_assignments', 'fuel_level')) {
                $table->decimal('fuel_level', 5, 2)->nullable()->comment('Fuel level at assignment start/end');
            }
            
            if (!Schema::hasColumn('vehicle_assignments', 'mileage_start')) {
                $table->integer('mileage_start')->nullable()->comment('Mileage at assignment start');
            }
            
            if (!Schema::hasColumn('vehicle_assignments', 'mileage_end')) {
                $table->integer('mileage_end')->nullable()->comment('Mileage at assignment end');
            }
            
            if (!Schema::hasColumn('vehicle_assignments', 'special_requirements')) {
                $table->json('special_requirements')->nullable()->comment('Special assignment requirements');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_assignments', function (Blueprint $table) {
            $table->dropColumn([
                'maintenance_window',
                'fuel_level', 
                'mileage_start',
                'mileage_end',
                'special_requirements'
            ]);
        });
    }
};
