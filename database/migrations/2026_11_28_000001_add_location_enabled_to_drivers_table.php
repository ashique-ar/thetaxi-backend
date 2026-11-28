<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->boolean('location_enabled')->nullable()->after('current_longitude');
            $table->timestampTz('location_status_updated_at')->nullable()->after('location_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['location_enabled', 'location_status_updated_at']);
        });
    }
};
