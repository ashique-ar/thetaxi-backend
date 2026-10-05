<?php

it('uses portable unique-index changes and protects rollback from duplicate contexts', function () {
    $migration = file_get_contents(database_path('migrations/2026_09_23_000004_fix_user_context_uniqueness.php'));

    expect($migration)
        ->toContain("Schema::table('user_contexts', fn (\$table) => \$table->dropUnique(self::LEGACY_CONSTRAINT))")
        ->toContain("->havingRaw('COUNT(*) > 1')")
        ->toContain("throw new RuntimeException('Cannot restore legacy user context uniqueness while duplicate rows exist.')")
        ->toContain("['user_id', 'context_type', 'is_active']")
        ->not->toContain('ALTER TABLE user_contexts DROP CONSTRAINT', 'ALTER TABLE user_contexts ADD CONSTRAINT');
});
