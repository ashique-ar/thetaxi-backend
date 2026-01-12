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
        // Use raw SQL with explicit casting and IF EXISTS checks to make this safe on PostgreSQL
        // Drop index (some installs create an index instead of a named constraint)
        \DB::statement('DROP INDEX IF EXISTS booking_terms_booking_id_terms_and_condition_id_index');
        // Drop unique constraint and foreign key if they exist
        \DB::statement('ALTER TABLE booking_terms DROP CONSTRAINT IF EXISTS booking_terms_booking_id_terms_and_condition_id_unique');
        \DB::statement('ALTER TABLE booking_terms DROP CONSTRAINT IF EXISTS booking_terms_booking_id_foreign');

        // Safely alter column type to UUID using USING clause
        // This explicitly casts existing values to uuid where possible
        \DB::statement('ALTER TABLE booking_terms ALTER COLUMN booking_id TYPE uuid USING booking_id::uuid');
        \DB::statement('ALTER TABLE booking_terms ALTER COLUMN booking_id SET NOT NULL');
        \DB::statement('ALTER TABLE booking_terms ALTER COLUMN booking_id DROP DEFAULT');
        // Drop identity if exists (Postgres 10+)
        \DB::statement('ALTER TABLE booking_terms ALTER COLUMN booking_id DROP IDENTITY IF EXISTS');

        // Recreate unique constraint and foreign key
        \DB::statement('ALTER TABLE booking_terms ADD CONSTRAINT booking_terms_booking_id_terms_and_condition_id_unique UNIQUE (booking_id, terms_and_condition_id)');
        \DB::statement('ALTER TABLE booking_terms ADD CONSTRAINT booking_terms_booking_id_foreign FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Attempt a safe revert back to integer where possible. This may leave NULLs where UUIDs can't be cast to integers.
        // Drop constraints first
        \DB::statement('ALTER TABLE booking_terms DROP CONSTRAINT IF EXISTS booking_terms_booking_id_foreign');
        \DB::statement('ALTER TABLE booking_terms DROP CONSTRAINT IF EXISTS booking_terms_booking_id_terms_and_condition_id_unique');

        // Try casting back to bigint for values that are numeric; non-numeric UUIDs will become NULL
        \DB::statement("ALTER TABLE booking_terms ALTER COLUMN booking_id TYPE bigint USING (CASE WHEN booking_id ~ '^[0-9]+$' THEN booking_id::bigint ELSE NULL END)");

        // Make booking_id nullable again (since some values may have become NULL)
        \DB::statement('ALTER TABLE booking_terms ALTER COLUMN booking_id DROP NOT NULL');

        // Recreate the unique constraint on the (possibly nullable) columns
        \DB::statement('ALTER TABLE booking_terms ADD CONSTRAINT booking_terms_booking_id_terms_and_condition_id_unique UNIQUE (booking_id, terms_and_condition_id)');
    }
};
