<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_attendance_access_commands', function (Blueprint $table): void {
            $table->foreignUuid('retry_requested_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('hr_attendance_access_commands')->whereNotNull('retry_requested_by')->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile access-command retry actor evidence first.');
        }

        Schema::table('hr_attendance_access_commands', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('retry_requested_by');
        });
    }
};
