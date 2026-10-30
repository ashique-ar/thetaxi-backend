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
        if (Schema::hasTable('activity_log') && Schema::hasColumn('activity_log', 'properties')) {
            foreach (DB::table('activity_log')->where('log_name', 'hr-relations-sensitive-access')->get(['id', 'properties']) as $activity) {
                $properties = is_array($activity->properties)
                    ? $activity->properties
                    : json_decode((string) ($activity->properties ?: '{}'), true, 512, JSON_THROW_ON_ERROR);
                unset($properties['company_id'], $properties['attributes']['company_id'], $properties['old']['company_id']);
                DB::table('activity_log')->where('id', $activity->id)->update([
                    'properties' => json_encode($properties, JSON_THROW_ON_ERROR),
                ]);
            }
        }

        $tableName = 'hr_relation_cases';
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
            if (($index['primary'] ?? false) || $index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                continue;
            }

            TenantIndexDropper::drop($tableName, $index);
            $columns = array_values(array_diff($index['columns'], ['company_id']));
            if ($columns !== [] && ! in_array($columns, $existingColumnSets, true)) {
                $name = 'hr_relation_cases_global_'.substr(sha1(implode('|', $columns)), 0, 8).'_idx';
                Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
                $existingColumnSets[] = $columns;
            }
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    public function down(): void
    {
        throw new RuntimeException('Employee relations cases cannot regain tenant company scope.');
    }
};
