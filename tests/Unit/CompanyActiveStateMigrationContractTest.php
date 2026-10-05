<?php

it('adds company active state before default governance and keeps it on rollback', function () {
    $migrationPath = database_path('migrations/2026_08_31_171900_add_active_state_to_companies.php');
    $governancePath = database_path('migrations/2026_08_31_172000_govern_default_staff_company.php');
    $migration = file_get_contents($migrationPath);
    $governance = file_get_contents($governancePath);

    expect(basename($migrationPath) < basename($governancePath))->toBeTrue()
        ->and($migration)->toContain("Schema::hasColumn('companies', 'is_active')", "boolean('is_active')->default(true)", "whereNull('is_active')->update(['is_active' => true])")
        ->and($migration)->not->toContain("dropColumn('is_active')")
        ->and($governance)->toContain("where('is_active', true)", "in_array(\$driver, ['mysql', 'sqlsrv', 'pgsql', 'sqlite'], true)", "\$driver === 'mysql'", "\$driver === 'sqlsrv'", "storedAs('CASE WHEN is_default = 1 AND deleted_at IS NULL THEN 1 ELSE NULL END')", 'companies_one_active_default_unique')
        ->and($governance)->toContain("dropUnique('companies_one_active_default_unique')", "dropColumn('active_default_guard')", 'DROP INDEX companies_one_active_default_unique ON companies')
        ->and(file_get_contents(app_path('Models/Company.php')))->toContain("protected \$hidden = ['active_default_guard'];");
});
