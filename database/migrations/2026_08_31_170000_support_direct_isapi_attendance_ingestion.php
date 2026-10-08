<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_attendance_ingestion_requests', function (Blueprint $table) {
            $table->foreignUuid('device_id')->nullable()->after('connector_id')->constrained('hr_attendance_devices')->restrictOnDelete();
            $table->uuid('connector_id')->nullable()->change();
            $table->index(['device_id', 'received_at'], 'hr_attendance_ingestion_device_received_idx');
        });
        Schema::table('hr_attendance_raw_events', function (Blueprint $table) {
            $table->uuid('connector_id')->nullable()->change();
            $table->unique(['device_id', 'provider_event_id'], 'hr_attendance_device_provider_event_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('hr_attendance_ingestion_requests')->where(function ($query): void {
            $query->whereNotNull('device_id')->orWhereNull('connector_id');
        })->exists()
            || DB::table('hr_attendance_raw_events')->where(function ($query): void {
                $query->whereNotNull('device_id')->orWhereNull('connector_id');
            })->exists()) {
            throw new \RuntimeException('Rollback refused: reconcile direct-device attendance provenance before removing device links or restoring required connectors.');
        }

        Schema::table('hr_attendance_raw_events', fn (Blueprint $table) => $table->dropUnique('hr_attendance_device_provider_event_unique'));
        Schema::table('hr_attendance_ingestion_requests', function (Blueprint $table) {
            $table->dropIndex('hr_attendance_ingestion_device_received_idx');
            $table->dropConstrainedForeignId('device_id');
            $table->uuid('connector_id')->nullable(false)->change();
        });
        Schema::table('hr_attendance_raw_events', function (Blueprint $table) {
            $table->uuid('connector_id')->nullable(false)->change();
        });
    }
};
