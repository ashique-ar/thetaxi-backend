<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_pricing_slab_definitions', function (Blueprint $table) {
            $table->integer('min_minutes')->nullable()->after('type');
            $table->integer('max_minutes')->nullable()->after('min_minutes');
            $table->index(['service_type_id', 'min_minutes', 'max_minutes'], 'pricing_slab_minute_range_idx');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_pricing_slab_definitions', function (Blueprint $table) {
            $table->dropIndex('pricing_slab_minute_range_idx');
            $table->dropColumn(['min_minutes', 'max_minutes']);
        });
    }
};
