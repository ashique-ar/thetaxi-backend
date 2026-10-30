<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'company_id')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->uuid('company_id')->nullable()->change());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            if (DB::table($tableName)->whereNull('company_id')->exists()) {
                throw new RuntimeException("Cannot restore required {$tableName}.company_id while installation-wide rows exist.");
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->uuid('company_id')->nullable(false)->change());
        }
    }
};
