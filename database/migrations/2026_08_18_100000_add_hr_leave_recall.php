<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §5.9: "Request, multi-level approval, delegate approval, cancellation,
 * recall, extension, return-to-work, and HR override." Cancellation,
 * extension, return-to-work, and HR override already exist in
 * `LeaveWorkflowService`; this adds the missing employer-initiated recall
 * as an additive column triple on `hr_leave_requests`, distinct from the
 * employee-initiated `actual_return_date` pair added for return-to-work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            $table->date('recall_date')->nullable()->after('return_confirmed_at');
            $table->foreignUuid('recalled_by')->nullable()->after('recall_date')->constrained('users')->nullOnDelete();
            $table->timestamp('recalled_at')->nullable()->after('recalled_by');
        });
    }

    public function down(): void
    {
        if (DB::table('hr_leave_requests')->whereNotNull('recall_date')->exists()) {
            throw new \LogicException('Refusing to drop retained leave recalls.');
        }

        Schema::table('hr_leave_requests', function (Blueprint $table) {
            $table->dropColumn(['recalled_at']);
            $table->dropConstrainedForeignId('recalled_by');
            $table->dropColumn('recall_date');
        });
    }
};
