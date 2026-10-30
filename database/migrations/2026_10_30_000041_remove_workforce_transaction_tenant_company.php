<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hr_leave_balance_accounts', 'hr_leave_requests', 'hr_work_requests', 'hr_timesheets', 'hr_payroll_input_facts'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            $indexes = Schema::getIndexes($tableName);
            $existingColumnSets = array_map(static fn (array $index): array => $index['columns'], $indexes);
            $replacementIndexes = [];
            foreach ($indexes as $index) {
                if ($index['type'] !== 'primary' && in_array('company_id', $index['columns'], true)) {
                    TenantIndexDropper::drop($tableName, $index);
                    $columns = array_values(array_diff($index['columns'], ['company_id']));
                    if ($columns !== [] && ! in_array($columns, $existingColumnSets, true)) {
                        $replacementIndexes[implode('|', $columns)] = $columns;
                    }
                }
            }

            foreach ($replacementIndexes as $columns) {
                $name = substr($tableName, 0, 22).'_global_'.substr(implode('_', $columns), 0, 20).'_idx';
                Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Workforce transactions cannot regain tenant company scope.');
    }
};
