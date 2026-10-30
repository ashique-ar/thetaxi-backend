<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'hr_shift_definitions',
        'hr_attendance_policies',
        'hr_roster_assignments',
    ];

    public function up(): void
    {
        // Configuration and roster rows already have stable IDs and explicit
        // staff/configuration links. Keep every row; drop only tenant ownership.
        if (Schema::hasTable('hr_work_calendars') && Schema::hasColumn('hr_work_calendars', 'company_id')) {
            Schema::table('hr_work_calendars', fn (Blueprint $table) => $table->uuid('company_id')->nullable()->change());
        }

        foreach (self::TABLES as $tableName) {
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

                Schema::table($tableName, fn (Blueprint $table) => $index['unique']
                    ? $table->dropUnique($index['name'])
                    : $table->dropIndex($index['name']));
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->uuid('company_id')->nullable()->index());
        }
    }
};
