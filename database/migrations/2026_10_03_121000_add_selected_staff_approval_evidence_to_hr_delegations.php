<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_approval_delegations') || Schema::hasColumn('hr_approval_delegations', 'approved_by_staff_id')) {
            return;
        }

        Schema::table('hr_approval_delegations', function (Blueprint $table): void {
            $table->foreignUuid('approved_by_staff_id')->nullable()->constrained('staff')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('hr_approval_delegations', 'approved_by_staff_id')) {
            return;
        }

        if (DB::table('hr_approval_delegations')->whereNotNull('approved_by_staff_id')->exists()) {
            throw new RuntimeException('Cannot roll back selected Staff approval evidence while delegation decisions reference it.');
        }

        Schema::table('hr_approval_delegations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by_staff_id');
        });
    }
};
