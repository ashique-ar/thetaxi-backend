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
        Schema::table('bookings', function (Blueprint $table) {
            // Change from_time and to_time from dateTime to time
            $table->time('from_time')->nullable()->change();
            $table->time('to_time')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Revert back to dateTime
            $table->dateTime('from_time')->nullable()->change();
            $table->dateTime('to_time')->nullable()->change();
        });
    }
};