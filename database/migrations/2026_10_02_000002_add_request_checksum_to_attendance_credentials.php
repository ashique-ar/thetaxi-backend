<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('hr_attendance_credential_events', function (Blueprint $table) {
            $table->dropColumn('request_checksum');
        });
    }
};
