<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds default_vehicle_id to drivers table if not already present.
     * This column may already exist from migration 2025_08_09_062145.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('drivers', 'default_vehicle_id')) {
            Schema::table('drivers', function (Blueprint $table) {
                $table->uuid('default_vehicle_id')->nullable()->after('id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('drivers', 'default_vehicle_id')) {
            Schema::table('drivers', function (Blueprint $table) {
                $table->dropForeign(['default_vehicle_id']);
                $table->dropColumn('default_vehicle_id');
            });
        }
    }
};
