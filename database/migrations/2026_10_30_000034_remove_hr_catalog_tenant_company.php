<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_organization_change_events')) {
            foreach (DB::table('hr_organization_change_events')->get(['id', 'before_snapshot', 'after_snapshot']) as $event) {
                $before = $event->before_snapshot ? json_decode((string) $event->before_snapshot, true, 512, JSON_THROW_ON_ERROR) : null;
                $after = json_decode((string) $event->after_snapshot, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($before)) unset($before['company_id']);
                unset($after['company_id']);
                DB::table('hr_organization_change_events')->where('id', $event->id)->update([
                    'before_snapshot' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                    'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR),
                ]);
            }
        }

        foreach (['hr_organization_units', 'hr_job_families', 'hr_job_grades', 'hr_designations', 'hr_positions', 'hr_payroll_groups', 'hr_document_types', 'hr_organization_change_events'] as $tableName) {
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
                $dropMethod = $index['type'] === 'unique' ? 'dropUnique' : 'dropIndex';
                Schema::table($tableName, fn (Blueprint $table) => $table->{$dropMethod}($index['name']));
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('HR organization, job, position, payroll, and document catalogs cannot regain tenant company scope.');
    }
};
