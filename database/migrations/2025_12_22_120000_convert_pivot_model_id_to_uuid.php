<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The original pivot columns are already UUID-compatible on SQLite.
        // Everything below uses PostgreSQL catalog queries, regex operators,
        // casts, and ALTER COLUMN syntax.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Cast model_id columns to uuid to avoid uuid = character varying comparison errors on Postgres
        DB::transaction(function () {
            // Helper: drop any primary key constraint on a table if present
            $dropPrimary = function (string $table) {
                $pk = DB::select("SELECT conname FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'p'", [$table]);
                if (!empty($pk)) {
                    foreach ($pk as $row) {
                        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$row->conname}");
                    }
                }
            };

            // model_has_roles
            $dropPrimary('model_has_roles');
            DB::statement('DROP INDEX IF EXISTS model_has_roles_model_id_model_type_index');

            // Ensure model_id values are valid UUIDs to avoid cast errors
            $badRoles = DB::select("SELECT model_id FROM model_has_roles WHERE model_id IS NOT NULL AND NOT (model_id ~ '^[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}$') LIMIT 10");
            if (!empty($badRoles)) {
                $vals = array_map(function($r){ return $r->model_id; }, $badRoles);
                throw new \Exception('Found non-UUID model_id values in model_has_roles (sample): ' . implode(', ', $vals) . '. Please convert these to UUIDs (or remove rows) before running this migration.');
            }

            DB::statement("ALTER TABLE model_has_roles ALTER COLUMN model_id TYPE uuid USING model_id::uuid");
            DB::statement('CREATE INDEX model_has_roles_model_id_model_type_index ON model_has_roles (model_id, model_type)');
            // Recreate primary key (after ensuring any PK removed)
            DB::statement('ALTER TABLE model_has_roles ADD PRIMARY KEY (role_id, model_id, model_type)');

            // model_has_permissions
            $dropPrimary('model_has_permissions');
            DB::statement('DROP INDEX IF EXISTS model_has_permissions_model_id_model_type_index');

            // Ensure model_id values are valid UUIDs to avoid cast errors
            $badPerms = DB::select("SELECT model_id FROM model_has_permissions WHERE model_id IS NOT NULL AND NOT (model_id ~ '^[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}$') LIMIT 10");
            if (!empty($badPerms)) {
                $vals = array_map(function($r){ return $r->model_id; }, $badPerms);
                throw new \Exception('Found non-UUID model_id values in model_has_permissions (sample): ' . implode(', ', $vals) . '. Please convert these to UUIDs (or remove rows) before running this migration.');
            }

            DB::statement("ALTER TABLE model_has_permissions ALTER COLUMN model_id TYPE uuid USING model_id::uuid");
            DB::statement('CREATE INDEX model_has_permissions_model_id_model_type_index ON model_has_permissions (model_id, model_type)');
            DB::statement('ALTER TABLE model_has_permissions ADD PRIMARY KEY (permission_id, model_id, model_type)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function () {
            // Helper: drop any primary key constraint on a table if present
            $dropPrimary = function (string $table) {
                $pk = DB::select("SELECT conname FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'p'", [$table]);
                if (!empty($pk)) {
                    foreach ($pk as $row) {
                        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$row->conname}");
                    }
                }
            };

            // Revert model_has_roles
            $dropPrimary('model_has_roles');
            DB::statement('DROP INDEX IF EXISTS model_has_roles_model_id_model_type_index');
            DB::statement("ALTER TABLE model_has_roles ALTER COLUMN model_id TYPE varchar USING model_id::text");
            DB::statement('CREATE INDEX model_has_roles_model_id_model_type_index ON model_has_roles (model_id, model_type)');
            DB::statement('ALTER TABLE model_has_roles ADD PRIMARY KEY (role_id, model_id, model_type)');

            // Revert model_has_permissions
            $dropPrimary('model_has_permissions');
            DB::statement('DROP INDEX IF EXISTS model_has_permissions_model_id_model_type_index');
            DB::statement("ALTER TABLE model_has_permissions ALTER COLUMN model_id TYPE varchar USING model_id::text");
            DB::statement('CREATE INDEX model_has_permissions_model_id_model_type_index ON model_has_permissions (model_id, model_type)');
            DB::statement('ALTER TABLE model_has_permissions ADD PRIMARY KEY (permission_id, model_id, model_type)');
        });
    }
};
