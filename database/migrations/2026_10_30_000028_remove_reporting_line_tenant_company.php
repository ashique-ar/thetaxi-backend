<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hr_reporting_lines', 'hr_reporting_line_events'] as $tableName) {
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

        if (Schema::hasTable('hr_reporting_line_events')) {
            foreach (DB::table('hr_reporting_line_events')->get(['id', 'before_snapshot', 'after_snapshot']) as $event) {
                $before = $event->before_snapshot ? json_decode($event->before_snapshot, true) : null;
                $after = json_decode($event->after_snapshot, true);
                unset($before['company_id'], $after['company_id']);
                DB::table('hr_reporting_line_events')->where('id', $event->id)->update([
                    'before_snapshot' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                    'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR),
                ]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Reporting-line tenant company fields cannot be restored without reintroducing company-scoped staff access.');
    }
};
