<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds dynamic form fields to service_types table.
     * Does NOT modify existing service type data.
     */
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            // Only add columns if they don't exist
            if (!Schema::hasColumn('service_types', 'uses_dropoff_time')) {
                $table->boolean('uses_dropoff_time')->default(true)->after('pricing_mode');
            }
            
            if (!Schema::hasColumn('service_types', 'allow_return_trip')) {
                $table->boolean('allow_return_trip')->default(false)->after('uses_dropoff_time');
            }
            
            if (!Schema::hasColumn('service_types', 'frontend_category')) {
                $table->string('frontend_category', 50)->nullable()->after('allow_return_trip');
            }

            if (!Schema::hasColumn('service_types', 'form_config')) {
                $table->json('form_config')->nullable()->after('frontend_category')
                    ->comment('Custom form field configuration');
            }
        });

        // Note: We do NOT update existing service types here
        // Service types should be configured manually or via separate seeder
        // This preserves existing business logic and configurations
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            if (Schema::hasColumn('service_types', 'form_config')) {
                $table->dropColumn('form_config');
            }

            if (Schema::hasColumn('service_types', 'uses_dropoff_time')) {
                $table->dropColumn('uses_dropoff_time');
            }
            
            if (Schema::hasColumn('service_types', 'allow_return_trip')) {
                $table->dropColumn('allow_return_trip');
            }
            
            if (Schema::hasColumn('service_types', 'frontend_category')) {
                $table->dropColumn('frontend_category');
            }
        });
    }
};
