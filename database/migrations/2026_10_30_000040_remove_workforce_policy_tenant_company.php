<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hr_leave_types', 'hr_leave_policies', 'hr_leave_policy_assignments', 'hr_work_request_policies'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            $toReplace = [];
            $unaffectedColumnSets = [];
            foreach (Schema::getIndexes($tableName) as $index) {
                if ($index['type'] === 'primary') {
                    continue;
                }
                if (in_array('company_id', $index['columns'], true)) {
                    $toReplace[] = $index;
                } else {
                    $unaffectedColumnSets[] = $index['columns'];
                }
            }

            foreach ($toReplace as $index) {
                TenantIndexDropper::drop($tableName, $index);
            }

            $seen = [];
            foreach ($toReplace as $index) {
                $columns = array_values(array_diff($index['columns'], ['company_id']));
                if ($columns === [] || in_array($columns, $unaffectedColumnSets, true)) {
                    continue;
                }
                $key = implode('|', $columns);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $unique = $index['type'] === 'unique' && ! DB::table($tableName)
                    ->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists();
                $name = substr($tableName, 0, 24).'_global_'.substr(implode('_', $columns), 0, 18).'_'.($unique ? 'unique' : 'idx');
                Schema::table($tableName, function (Blueprint $table) use ($columns, $name, $unique): void {
                    $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
                });
                $unaffectedColumnSets[] = $columns;
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Workforce policy company scope cannot be restored without reintroducing location-based HR configuration.');
    }
};
