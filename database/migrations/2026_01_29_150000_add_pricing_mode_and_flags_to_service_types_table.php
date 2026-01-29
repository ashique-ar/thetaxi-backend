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
        Schema::table('service_types', function (Blueprint $table) {
            $table->string('pricing_mode')->nullable()->default('trip')->after('thumbnail');
            $table->boolean('uses_dropoff_time')->nullable()->default(true)->after('pricing_mode');
            $table->boolean('allow_return_trip')->nullable()->default(false)->after('uses_dropoff_time');
            $table->string('frontend_category')->nullable()->after('allow_return_trip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->dropColumn(['pricing_mode', 'uses_dropoff_time', 'allow_return_trip', 'frontend_category']);
        });
    }
};
