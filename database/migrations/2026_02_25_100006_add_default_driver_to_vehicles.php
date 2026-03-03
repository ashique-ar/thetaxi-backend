<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds default_driver_id to vehicles table if not already present.
     * This column may already exist from migration 2025_08_29_042624.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('vehicles', 'default_driver_id')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->uuid('default_driver_id')->nullable()->after('id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('vehicles', 'default_driver_id')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropForeign(['default_driver_id']);
                $table->dropColumn('default_driver_id');
            });
        }
    }
};
