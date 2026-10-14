<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_correction_requests', function (Blueprint $table) {
            $table->foreignUuid('mapping_policy_id')->nullable()->after('current_result_id')->constrained('hr_attendance_policies')->restrictOnDelete();
            $table->json('mapping_snapshot')->nullable()->after('evidence_references');
        });
    }

    public function down(): void
    {
        if (DB::table('hr_attendance_correction_requests')->whereNotNull('mapping_policy_id')->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile attendance correction mapping policy evidence first.');
        }

        Schema::table('hr_attendance_correction_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mapping_policy_id');
            $table->dropColumn('mapping_snapshot');
        });
    }
};
