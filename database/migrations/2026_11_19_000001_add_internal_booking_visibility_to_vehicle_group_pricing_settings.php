<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vehicle_group_service_pricing_settings', function (Blueprint $table): void {
            $table->boolean('show_in_internal_bookings')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_group_service_pricing_settings', function (Blueprint $table): void {
            $table->dropColumn('show_in_internal_bookings');
        });
    }
};
