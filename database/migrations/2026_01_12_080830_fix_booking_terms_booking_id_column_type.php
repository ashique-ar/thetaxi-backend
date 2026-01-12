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
        Schema::table('booking_terms', function (Blueprint $table) {
            // Drop the unique constraint if it exists (using raw SQL for PostgreSQL)
            \DB::statement('ALTER TABLE booking_terms DROP CONSTRAINT IF EXISTS booking_terms_booking_id_terms_and_condition_id_unique');

            // Drop any foreign key constraint on booking_id if it exists
            \DB::statement('ALTER TABLE booking_terms DROP CONSTRAINT IF EXISTS booking_terms_booking_id_foreign');
        });

        Schema::table('booking_terms', function (Blueprint $table) {
            // Change booking_id from unsignedBigInteger to uuid
            $table->uuid('booking_id')->change();

            // Recreate the unique constraint
            $table->unique(['booking_id', 'terms_and_condition_id']);

            // Add foreign key constraint back if needed
            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_terms', function (Blueprint $table) {
            // Drop foreign key constraint
            $table->dropForeign(['booking_id']);

            // Drop the unique constraint
            $table->dropUnique(['booking_id', 'terms_and_condition_id']);

            // Revert booking_id back to unsignedBigInteger
            $table->unsignedBigInteger('booking_id')->change();

            // Recreate the unique constraint
            $table->unique(['booking_id', 'terms_and_condition_id']);
        });
    }
};
