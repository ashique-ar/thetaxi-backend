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
        Schema::table('vehicles', function (Blueprint $t) {
            $t->uuid('default_driver_id')->nullable()->after('id');
            $t->boolean('force_default_driver')->default(false)->after('default_driver_id');
            $t->boolean('allow_concurrent_assignments')->default(true)->after('force_default_driver');
        });

        Schema::table('drivers', function (Blueprint $t) {
            $t->uuid('default_vehicle_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
