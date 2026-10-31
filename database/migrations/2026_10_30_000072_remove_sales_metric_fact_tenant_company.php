<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'sales_metric_facts';
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }

        $indexes = Schema::getIndexes($tableName);
        $existingColumnSets = array_map(static fn (array $index): array => $index['columns'], $indexes);
        foreach ($indexes as $index) {
            if (($index['primary'] ?? false) || ($index['type'] ?? null) === 'primary'
                || ! in_array('company_id', $index['columns'], true)) {
                continue;
            }

            TenantIndexDropper::drop($tableName, $index);
            $columns = array_values(array_diff($index['columns'], ['company_id']));
            if ($columns === [] || ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'
                || in_array($columns, $existingColumnSets, true)) {
                continue;
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, 'sales_metric_profile_period_installation_idx'));
            $existingColumnSets[] = $columns;
        }

        $installationColumns = ['sales_profile_id', 'metric_type', 'occurred_on'];
        $hasInstallationIndex = collect(Schema::getIndexes($tableName))
            ->contains(fn (array $index) => $index['columns'] === $installationColumns);
        if (! $hasInstallationIndex) {
            Schema::table($tableName, fn (Blueprint $table) => $table->index(
                $installationColumns,
                'sales_metric_profile_period_installation_idx',
            ));
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    public function down(): void
    {
        throw new RuntimeException('Sales metric facts cannot regain tenant company scope.');
    }
};
