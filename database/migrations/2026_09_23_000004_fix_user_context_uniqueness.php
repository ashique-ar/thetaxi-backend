<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LEGACY_CONSTRAINT = 'unique_active_user_context';

    private const CONTEXT_UNIQUE_INDEX = 'user_contexts_user_type_context_unique';

    public function up(): void
    {
        // A user may have multiple contexts of the same type (for example, one
        // corporate context per employer). Only the concrete, non-deleted
        // context membership must be unique.
        DB::statement(sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS %s ON user_contexts (user_id, context_type, context_id) WHERE deleted_at IS NULL',
            self::CONTEXT_UNIQUE_INDEX
        ));

        DB::statement(sprintf(
            'ALTER TABLE user_contexts DROP CONSTRAINT IF EXISTS %s',
            self::LEGACY_CONSTRAINT
        ));
    }

    public function down(): void
    {
        DB::statement(sprintf(
            'DROP INDEX IF EXISTS %s',
            self::CONTEXT_UNIQUE_INDEX
        ));

        DB::statement(sprintf(
            'ALTER TABLE user_contexts ADD CONSTRAINT %s UNIQUE (user_id, context_type, is_active)',
            self::LEGACY_CONSTRAINT
        ));
    }
};
