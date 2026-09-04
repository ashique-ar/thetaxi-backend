<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §5.9: "Request, multi-level approval, delegate approval, cancellation,
 * recall, extension, return-to-work, and HR override." Approval/cancellation
 * already exist in `LeaveWorkflowService`; this adds the missing
 * return-to-work confirmation as an additive column pair on the existing
 * `hr_leave_requests` table, reusing its existing event/balance-entry ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            $table->date('actual_return_date')->nullable()->after('end_date');
            $table->foreignUuid('return_confirmed_by')->nullable()->after('decided_by')->constrained('users')->nullOnDelete();
            $table->timestamp('return_confirmed_at')->nullable()->after('return_confirmed_by');
        });
    }

    public function down(): void
    {
        if (DB::table('hr_leave_requests')->whereNotNull('actual_return_date')->exists()) {
            throw new \LogicException('Refusing to drop retained return-to-work confirmations.');
        }

        Schema::table('hr_leave_requests', function (Blueprint $table) {
            $table->dropColumn(['return_confirmed_at']);
            $table->dropConstrainedForeignId('return_confirmed_by');
            $table->dropColumn('actual_return_date');
        });
    }
};
