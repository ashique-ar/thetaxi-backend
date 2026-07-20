<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLE = 'vehicle_pricing_slab_definitions';
    private const LEGACY_UNIQUE = 'vehicle_pricing_slab_definitions_service_type_id_name_unique';
    private const ACTIVE_UNIQUE = 'vehicle_pricing_slab_definitions_service_type_name_active_unique';
    private const ACTIVE_NAME_COLUMN = 'active_unique_name';

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE ' . self::TABLE . ' DROP CONSTRAINT IF EXISTS ' . self::LEGACY_UNIQUE);
            DB::statement('DROP INDEX IF EXISTS ' . self::LEGACY_UNIQUE);
            DB::statement(
                'CREATE UNIQUE INDEX ' . self::ACTIVE_UNIQUE
                . ' ON ' . self::TABLE . ' (service_type_id, name) WHERE deleted_at IS NULL'
            );
            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE ' . self::TABLE . ' DROP INDEX ' . self::LEGACY_UNIQUE);
            DB::statement(
                'ALTER TABLE ' . self::TABLE
                . ' ADD COLUMN ' . self::ACTIVE_NAME_COLUMN
                . ' VARCHAR(255) GENERATED ALWAYS AS (IF(deleted_at IS NULL, name, NULL)) STORED'
            );
            DB::statement(
                'CREATE UNIQUE INDEX ' . self::ACTIVE_UNIQUE
                . ' ON ' . self::TABLE . ' (service_type_id, ' . self::ACTIVE_NAME_COLUMN . ')'
            );
            return;
        }

        DB::statement('DROP INDEX IF EXISTS ' . self::LEGACY_UNIQUE);
        DB::statement(
            'CREATE UNIQUE INDEX ' . self::ACTIVE_UNIQUE
            . ' ON ' . self::TABLE . ' (service_type_id, name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('DROP INDEX ' . self::ACTIVE_UNIQUE . ' ON ' . self::TABLE);
            DB::statement('ALTER TABLE ' . self::TABLE . ' DROP COLUMN ' . self::ACTIVE_NAME_COLUMN);
            DB::statement(
                'CREATE UNIQUE INDEX ' . self::LEGACY_UNIQUE
                . ' ON ' . self::TABLE . ' (service_type_id, name)'
            );
            return;
        }

        DB::statement('DROP INDEX IF EXISTS ' . self::ACTIVE_UNIQUE);
        DB::statement(
            'CREATE UNIQUE INDEX ' . self::LEGACY_UNIQUE
            . ' ON ' . self::TABLE . ' (service_type_id, name)'
        );
    }
};
