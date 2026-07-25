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
        Schema::table('booking_items', function (Blueprint $table) {
            if (!Schema::hasColumn('booking_items', 'trip_number')) {
                $table->integer('trip_number')->default(0)->after('booking_id');
                $table->index(['booking_id', 'trip_number']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_items', function (Blueprint $table) {
            if (Schema::hasColumn('booking_items', 'trip_number')) {
                $table->dropIndex(['booking_id', 'trip_number']);
                $table->dropColumn('trip_number');
            }
        });
    }
};
