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
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Drop unique constraints and indexes that reference (make_id, name)
            DB::statement(<<<'SQL'
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN SELECT conname
             FROM pg_constraint
             WHERE conrelid = 'vehicle_models'::regclass
               AND contype = 'u'
               AND pg_get_constraintdef(oid) ILIKE '%(make_id, name)%'
    LOOP
        EXECUTE format('ALTER TABLE vehicle_models DROP CONSTRAINT IF EXISTS %I;', r.conname);
    END LOOP;

    FOR r IN SELECT indexname
             FROM pg_indexes
             WHERE tablename = 'vehicle_models'
               AND indexdef ILIKE '%(make_id, name)%'
    LOOP
        EXECUTE format('DROP INDEX IF EXISTS %I;', r.indexname);
    END LOOP;
END $$;
SQL
            );
        } else {
            // MySQL / MariaDB: find indexes that include both columns and drop them
            $rows = DB::select("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicle_models' AND COLUMN_NAME IN ('make_id','name') GROUP BY INDEX_NAME HAVING COUNT(DISTINCT COLUMN_NAME) = 2");
            foreach ($rows as $row) {
                $index = $row->INDEX_NAME ?? $row->index_name ?? null;
                if ($index) {
                    try {
                        DB::statement("DROP INDEX `{$index}` ON vehicle_models;");
                    } catch (\Exception $e) {
                        // ignore
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
        // Not re-creating unique constraints here to avoid reintroducing failures.
    }
};
