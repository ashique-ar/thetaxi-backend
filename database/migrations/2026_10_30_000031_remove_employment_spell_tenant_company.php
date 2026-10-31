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
        if (Schema::hasTable('hr_employment_types')) {
            $duplicateCodes = DB::table('hr_employment_types')->select('code')->groupBy('code')
                ->havingRaw('COUNT(*) > 1')->limit(20)->pluck('code');
            if ($duplicateCodes->isNotEmpty()) {
                throw new RuntimeException('Cannot make HR employment type codes installation-wide; reconcile duplicates first: '.implode(', ', $duplicateCodes->all()));
            }
        }

        if (Schema::hasTable('activity_log') && Schema::hasColumn('activity_log', 'properties')) {
            foreach (DB::table('activity_log')->whereIn('log_name', ['HrEmploymentSpell', 'HrRehireCase'])->get(['id', 'properties']) as $activity) {
                $properties = is_array($activity->properties)
                    ? $activity->properties
                    : json_decode((string) ($activity->properties ?: '{}'), true, 512, JSON_THROW_ON_ERROR);
                unset($properties['attributes']['company_id'], $properties['old']['company_id']);
                DB::table('activity_log')->where('id', $activity->id)->update([
                    'properties' => json_encode($properties, JSON_THROW_ON_ERROR),
                ]);
            }
        }

        foreach (['hr_employment_types', 'hr_employment_spells', 'hr_rehire_cases'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            foreach (Schema::getIndexes($tableName) as $index) {
                if ($index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }
                TenantIndexDropper::drop($tableName, $index);
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        if (Schema::hasTable('hr_employment_types')) {
            $hasGlobalUnique = collect(Schema::getIndexes('hr_employment_types'))->contains(fn (array $index) =>
                $index['columns'] === ['code'] && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'));
            if (! $hasGlobalUnique) {
                Schema::table('hr_employment_types', fn (Blueprint $table) => $table->unique('code', 'hr_employment_types_code_unique'));
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Employment company scope cannot be restored without reintroducing tenant-based HR records.');
    }
};
