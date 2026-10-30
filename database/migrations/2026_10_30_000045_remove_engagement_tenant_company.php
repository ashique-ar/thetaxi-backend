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
            foreach (DB::table('activity_log')->where('log_name', 'hr-wellness-sensitive-access')->get(['id', 'properties']) as $activity) {
                $properties = is_array($activity->properties)
                    ? $activity->properties
                    : json_decode((string) ($activity->properties ?: '{}'), true, 512, JSON_THROW_ON_ERROR);
                unset($properties['company_id'], $properties['attributes']['company_id'], $properties['old']['company_id']);
                DB::table('activity_log')->where('id', $activity->id)->update([
                    'properties' => json_encode($properties, JSON_THROW_ON_ERROR),
                ]);
            }
        }

        foreach (['hr_announcements', 'hr_survey_versions', 'hr_recognition_nominations', 'hr_wellness_programs', 'hr_wellness_referrals'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            $indexes = Schema::getIndexes($tableName);
            $existingColumnSets = [];
            $replacements = [];
            foreach ($indexes as $index) {
                if (! in_array('company_id', $index['columns'], true)) {
                    $existingColumnSets[] = $index['columns'];
                    continue;
                }
                if (($index['primary'] ?? false) || $index['type'] === 'primary') {
                    continue;
                }

                TenantIndexDropper::drop($tableName, $index);
                $columns = array_values(array_diff($index['columns'], ['company_id']));
                if ($columns === []) {
                    continue;
                }

                $key = implode('|', $columns);
                $replacements[$key]['columns'] = $columns;
                $replacements[$key]['unique'] = ($replacements[$key]['unique'] ?? false)
                    || ($index['unique'] ?? false) || $index['type'] === 'unique';
            }

            foreach ($replacements as $replacement) {
                $columns = $replacement['columns'];
                $unique = $replacement['unique'] && ! DB::table($tableName)
                    ->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists();
                if (in_array($columns, $existingColumnSets, true) && ! $unique) {
                    continue;
                }

                $name = substr($tableName, 0, 22).'_global_'.substr(sha1(implode('|', $columns)), 0, 8).($unique ? '_unique' : '_idx');
                $this->createIndex($tableName, $columns, $name, $unique);
                $existingColumnSets[] = $columns;
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    private function createIndex(string $tableName, array $columns, string $baseName, bool $unique): void
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            $isUnique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
            if ($index['columns'] === $columns && (! $unique || $isUnique)) {
                return;
            }
        }

        $name = $baseName;
        $suffix = 1;
        while ($this->relationExists($name, $tableName)) {
            $name = $baseName.'_'.$suffix;
            $suffix++;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $name, $unique): void {
            $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
        });
    }

    private function relationExists(string $name, string $tableName): bool
    {
        if (DB::getDriverName() === 'pgsql') {
            return DB::table('pg_class as c')
                ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
                ->where('c.relname', $name)
                ->whereRaw('n.nspname = current_schema()')
                ->exists();
        }

        return Schema::hasIndex($tableName, $name);
    }

    public function down(): void
    {
        throw new RuntimeException('Engagement records cannot regain tenant company scope.');
    }
};
