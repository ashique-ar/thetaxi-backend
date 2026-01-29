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
        // Drop unique constraint and foreign key if they exist
        \DB::statement('ALTER TABLE booking_terms DROP CONSTRAINT IF EXISTS booking_terms_booking_id_terms_and_condition_id_unique');
        \DB::statement('ALTER TABLE booking_terms DROP CONSTRAINT IF EXISTS booking_terms_terms_and_condition_id_foreign');

        // Convert terms_and_condition_id safely:
        // 1) Convert current terms_and_condition_id to text so we can try casting
        // 2) Attempt to cast textual values to uuid only when they match UUID pattern, otherwise set to NULL
        \DB::statement('ALTER TABLE booking_terms ALTER COLUMN terms_and_condition_id TYPE text USING terms_and_condition_id::text');
        \DB::statement("ALTER TABLE booking_terms ALTER COLUMN terms_and_condition_id TYPE uuid USING (CASE WHEN terms_and_condition_id ~ '^[0-9a-fA-F\\-]{36}$' THEN terms_and_condition_id::uuid ELSE NULL END)");

        // Recreate unique constraint (allows NULLs)
        \DB::statement('ALTER TABLE booking_terms ADD CONSTRAINT booking_terms_booking_id_terms_and_condition_id_unique UNIQUE (booking_id, terms_and_condition_id)');

        // Add foreign key constraint to terms_and_conditions
        \DB::statement('ALTER TABLE booking_terms ADD CONSTRAINT booking_terms_terms_and_condition_id_foreign FOREIGN KEY (terms_and_condition_id) REFERENCES terms_and_conditions(id) ON DELETE CASCADE NOT VALID');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop constraints first
        \DB::statement('ALTER TABLE booking_terms DROP CONSTRAINT IF EXISTS booking_terms_terms_and_condition_id_foreign');
        \DB::statement('ALTER TABLE booking_terms DROP CONSTRAINT IF EXISTS booking_terms_booking_id_terms_and_condition_id_unique');

        // Try casting back to bigint for values that are numeric; non-numeric UUIDs will become NULL
        \DB::statement("ALTER TABLE booking_terms ALTER COLUMN terms_and_condition_id TYPE bigint USING (CASE WHEN terms_and_condition_id ~ '^[0-9]+$' THEN terms_and_condition_id::bigint ELSE NULL END)");

        // Recreate the unique constraint
        \DB::statement('ALTER TABLE booking_terms ADD CONSTRAINT booking_terms_booking_id_terms_and_condition_id_unique UNIQUE (booking_id, terms_and_condition_id)');
    }
};