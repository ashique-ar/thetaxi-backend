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
        Schema::table('vehicle_groups', function (Blueprint $table) {
            if (!Schema::hasColumn('vehicle_groups', 'passengers_count')) {
                $table->integer('passengers_count')->nullable()->after('images');
            }
            if (!Schema::hasColumn('vehicle_groups', 'hand_luggages')) {
                $table->integer('hand_luggages')->nullable()->after('passengers_count');
            }
            if (!Schema::hasColumn('vehicle_groups', 'air_conditioning')) {
                $table->boolean('air_conditioning')->nullable()->after('hand_luggages');
            }
            if (!Schema::hasColumn('vehicle_groups', 'refundable_deposit')) {
                $table->decimal('refundable_deposit', 10, 2)->nullable()->after('air_conditioning');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_groups', function (Blueprint $table) {
            $table->dropColumn(['passengers_count', 'hand_luggages', 'air_conditioning', 'refundable_deposit']);
        });
    }
};
