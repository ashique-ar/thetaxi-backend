<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For Postgres, changing an enum column to string is best done by dropping and recreating OR altering type
        // Dropping the check constraint and changing type is safer if it's an enum column
        
        // 1. Get the check constraint name (usually booking_items_status_check in Postgres)
        // 2. Drop it and change column type
        
        // We can just change the column type to string, Laravel/Postgres will handle basic conversion
        Schema::table('booking_items', function (Blueprint $table) {
            $table->string('status')->default('pending')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_items', function (Blueprint $table) {
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'completed'])->default('pending')->change();
        });
    }
};
