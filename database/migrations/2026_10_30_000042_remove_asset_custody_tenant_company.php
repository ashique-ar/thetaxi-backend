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
        foreach (['hr_asset_requests', 'hr_custody_assignments', 'hr_phone_subscriptions', 'hr_phone_usage_allocations'] as $tableName) {
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
            $replacements = [];
            foreach ($indexes as $index) {
                if (($index['primary'] ?? false) || $index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }
                TenantIndexDropper::drop($tableName, $index);
                $columns = array_values(array_diff($index['columns'], ['company_id']));
                if ($columns !== [] && ! in_array($columns, $existingColumnSets, true)) {
                    $replacements[implode('|', $columns)] = ['columns' => $columns, 'unique' => ($index['unique'] ?? false) || $index['type'] === 'unique'];
                }
            }

            foreach ($replacements as $replacement) {
                $columns = $replacement['columns'];
                $unique = $replacement['unique'] && ! DB::table($tableName)
                    ->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists();
                $name = substr($tableName, 0, 20).'_global_'.substr(implode('_', $columns), 0, 20).'_'.($unique ? 'unique' : 'idx');
                Schema::table($tableName, function (Blueprint $table) use ($columns, $name, $unique): void {
                    $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
                });
                $existingColumnSets[] = $columns;
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Asset custody and phone records cannot regain tenant company scope.');
    }
};
