<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        Schema::table('user_contexts', fn ($table) => $table->dropUnique(self::LEGACY_CONSTRAINT));
    }

    public function down(): void
    {
        $duplicates = DB::table('user_contexts')
            ->select('user_id', 'context_type', 'is_active')
            ->groupBy('user_id', 'context_type', 'is_active')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException('Cannot restore legacy user context uniqueness while duplicate rows exist.');
        }

        Schema::table('user_contexts', fn ($table) => $table->unique(
            ['user_id', 'context_type', 'is_active'],
            self::LEGACY_CONSTRAINT
        ));

        DB::statement(sprintf(
            'DROP INDEX IF EXISTS %s',
            self::CONTEXT_UNIQUE_INDEX
        ));
    }
};
