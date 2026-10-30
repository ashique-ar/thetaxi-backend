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
            foreach (DB::table('activity_log')->whereIn('log_name', ['hr-safety', 'hr-safety-sensitive-access'])->get(['id', 'properties']) as $activity) {
                $properties = is_array($activity->properties)
                    ? $activity->properties
                    : json_decode((string) ($activity->properties ?: '{}'), true, 512, JSON_THROW_ON_ERROR);
                unset($properties['company_id'], $properties['attributes']['company_id'], $properties['old']['company_id']);
                DB::table('activity_log')->where('id', $activity->id)->update([
                    'properties' => json_encode($properties, JSON_THROW_ON_ERROR),
                ]);
            }
        }

        foreach (['hr_safety_incidents', 'hr_hazards', 'hr_ppe_issuances', 'hr_fitness_restrictions', 'hr_safety_inspections', 'hr_safety_register_events'] as $tableName) {
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
                    $replacements[implode('|', $columns)] = [
                        'columns' => $columns,
                        'unique' => ($index['unique'] ?? false) || $index['type'] === 'unique',
                    ];
                }
            }

            foreach ($replacements as $replacement) {
                $columns = $replacement['columns'];
                $unique = $replacement['unique'] && ! DB::table($tableName)
                    ->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists();
                $name = substr($tableName, 0, 20).'_global_'.substr(sha1(implode('|', $columns)), 0, 8).'_'.($unique ? 'unique' : 'idx');
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
        throw new RuntimeException('Safety company scope cannot be restored without reintroducing tenant-based safety records.');
    }
};
