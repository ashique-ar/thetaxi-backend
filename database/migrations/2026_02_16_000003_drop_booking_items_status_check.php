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
        // Explicitly drop the check constraint for Postgres
        if (config('database.default') === 'pgsql') {
            DB::statement('ALTER TABLE booking_items DROP CONSTRAINT IF EXISTS booking_items_status_check');
        }
        
        // Ensure the column is indeed a string and has the right default
        Schema::table('booking_items', function (Blueprint $table) {
            $table->string('status')->default('pending')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Nothing to do here really, as we can't easily put back the exact constraint reliably 
        // without knowing all the allowed values at this point, but we could try:
        // Schema::table('booking_items', function (Blueprint $table) {
        //     $table->enum('status', ['pending', 'confirmed', 'cancelled', 'completed'])->default('pending')->change();
        // });
    }
};
