<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'hr_attendance_connectors',
        'hr_attendance_devices',
        'hr_attendance_person_mappings',
        'hr_attendance_raw_events',
        'hr_attendance_quarantine_items',
        'hr_attendance_access_commands',
        'hr_attendance_sync_runs',
        'hr_attendance_identity_dispositions',
        'hr_attendance_credential_events',
        'hr_attendance_access_groups',
        'hr_attendance_device_alerts',
        'hr_attendance_device_config_events',
        'hr_attendance_device_maintenance_commands',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            $indexes = Schema::getIndexes($tableName);
            $existingColumnSets = array_map(
                static fn (array $index): array => $index['columns'],
                $indexes
            );
            foreach ($indexes as $index) {
                if ($index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }
                $dropMethod = $index['type'] === 'unique' ? 'dropUnique' : 'dropIndex';
                Schema::table($tableName, fn (Blueprint $table) => $table->{$dropMethod}($index['name']));

                $locationIndependentColumns = array_values(array_diff($index['columns'], ['company_id']));
                if ($locationIndependentColumns === [] || in_array($locationIndependentColumns, $existingColumnSets, true)) {
                    continue;
                }

                $indexName = substr($tableName, 0, 28).'_'.substr(hash('sha1', implode('_', $locationIndependentColumns)), 0, 12).'_idx';
                Schema::table($tableName, fn (Blueprint $table) => $table->index($locationIndependentColumns, $indexName));
                $existingColumnSets[] = $locationIndependentColumns;
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Attendance company tenant metadata cannot be restored after installation-wide operation.');
    }
};
