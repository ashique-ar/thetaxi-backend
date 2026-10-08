<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_credential_events', function (Blueprint $table) {
            $table->char('request_checksum', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('hr_attendance_credential_events')->whereNotNull('request_checksum')->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile attendance credential idempotency evidence first.');
        }

        Schema::table('hr_attendance_credential_events', function (Blueprint $table) {
            $table->dropColumn('request_checksum');
        });
    }
};
