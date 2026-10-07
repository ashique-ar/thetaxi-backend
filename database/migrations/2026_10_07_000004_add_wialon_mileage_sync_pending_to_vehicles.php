<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->boolean('wialon_mileage_sync_pending')->default(false);
            $table->timestamp('wialon_mileage_sync_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['wialon_mileage_sync_pending', 'wialon_mileage_sync_requested_at']);
        });
    }
};
