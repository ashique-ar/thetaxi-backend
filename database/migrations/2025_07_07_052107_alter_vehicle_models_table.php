<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Make make_id nullable first (if the column exists)
        Schema::table('vehicle_models', function (Blueprint $table) {
            if (Schema::hasColumn('vehicle_models', 'make_id')) {
                $table->uuid('make_id')->nullable()->change();
            }
        });

        // Drop common unique indexes/constraints if they exist (support Postgres/MySQL)
        $indexesToDrop = [
            'vehicle_models_make_name_unique',
            'vehicle_models_name_unique',
            'vehicle_models_make_id_unique',
            'vehicle_models_make_name_unique',
        ];

        foreach ($indexesToDrop as $index) {
            try {
                // Postgres: DROP INDEX IF EXISTS "index";
                DB::statement("DROP INDEX IF EXISTS \"{$index}\";");
            } catch (\Exception $e) {
                // If Postgres drop failed, try dropping as a constraint (for completeness)
                try {
                    DB::statement("ALTER TABLE vehicle_models DROP CONSTRAINT IF EXISTS \"{$index}\";");
                } catch (\Exception $e) {
                    // MySQL: DROP INDEX index ON table
                    try {
                        DB::statement("DROP INDEX `{$index}` ON vehicle_models;");
                    } catch (\Exception $ex) {
                        // ignore if index/constraint doesn't exist
                    }
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Attempt to restore previous unique constraints (best-effort)
        if (Schema::hasTable('vehicle_models')) {
            try {
                Schema::table('vehicle_models', function (Blueprint $table) {
                    // Add back unique constraints if they don't exist
                    $sm = Schema::getConnection()->getDoctrineSchemaManager();
                    // Create composite unique index on make_id + name
                    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS vehicle_models_make_name_unique ON vehicle_models (make_id, name);');
                    // Create unique index on name
                    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS vehicle_models_name_unique ON vehicle_models (name);');
                });
            } catch (\Exception $e) {
                // If recreation fails, just log and continue
                // Logging isn't available in migration easily; ignoring silently
            }
        }
    }
};
