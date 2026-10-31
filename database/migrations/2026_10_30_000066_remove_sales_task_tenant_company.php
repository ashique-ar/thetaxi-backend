<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'sales_tasks';
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }

        $existingColumnSets = array_map(static fn (array $index): array => $index['columns'], Schema::getIndexes($tableName));
        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['primary'] ?? false) || ! in_array('company_id', $index['columns'], true)) {
                continue;
            }

            TenantIndexDropper::drop($tableName, $index);
            $columns = array_values(array_diff($index['columns'], ['company_id']));
            if ($columns === [] || in_array($columns, $existingColumnSets, true)) {
                continue;
            }

            $name = 'sales_task_installation_lookup_idx';
            $suffix = 1;
            while (Schema::hasIndex($tableName, $name)) {
                $name = 'sales_task_installation_lookup_idx_'.$suffix++;
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
            $existingColumnSets[] = $columns;
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    public function down(): void
    {
        throw new RuntimeException('Sales tasks cannot regain tenant company scope.');
    }
};
