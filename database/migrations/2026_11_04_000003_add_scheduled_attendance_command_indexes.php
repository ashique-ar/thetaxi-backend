<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_access_commands', function (Blueprint $table): void {
            $table->index(['status', 'updated_at'], 'hr_access_commands_status_updated_idx');
            $table->index(['status', 'next_attempt_at', 'approved_at'], 'hr_access_commands_delivery_idx');
        });

        Schema::table('hr_attendance_device_maintenance_commands', function (Blueprint $table): void {
            $table->index(['status', 'approved_at'], 'hr_device_maintenance_status_approved_idx');
            $table->index(['status', 'executed_at'], 'hr_device_maintenance_status_executed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_device_maintenance_commands', function (Blueprint $table): void {
            $table->dropIndex('hr_device_maintenance_status_executed_idx');
            $table->dropIndex('hr_device_maintenance_status_approved_idx');
        });

        Schema::table('hr_attendance_access_commands', function (Blueprint $table): void {
            $table->dropIndex('hr_access_commands_delivery_idx');
            $table->dropIndex('hr_access_commands_status_updated_idx');
        });
    }
};
