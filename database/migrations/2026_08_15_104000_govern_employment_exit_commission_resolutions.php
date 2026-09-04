<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_commission_hold_resolutions DROP CONSTRAINT sales_commission_hold_resolution_kind');
        }

        Schema::table('sales_commission_hold_resolutions', function (Blueprint $table) {
            $table->uuid('receipt_finality_event_id')->nullable()->change();
            $table->foreignUuid('employment_staff_id')->nullable()
                ->constrained('staff')->restrictOnDelete();
            $table->timestampTz('employment_ended_at')->nullable();
            $table->foreignUuid('employment_terminated_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->index(['employment_staff_id', 'employment_ended_at'], 'commission_hold_resolution_employment_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sales_commission_hold_resolutions ADD CONSTRAINT sales_commission_hold_resolution_kind CHECK (resolution_kind IN ('failed_finality_no_entitlement', 'employment_exit_no_entitlement'))");
        }
    }

    public function down(): void
    {
        if (DB::table('sales_commission_hold_resolutions')
            ->where('resolution_kind', 'employment_exit_no_entitlement')->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile immutable employment-exit commission resolutions first.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_commission_hold_resolutions DROP CONSTRAINT sales_commission_hold_resolution_kind');
        }

        Schema::table('sales_commission_hold_resolutions', function (Blueprint $table) {
            $table->dropIndex('commission_hold_resolution_employment_idx');
            $table->dropConstrainedForeignId('employment_terminated_by');
            $table->dropConstrainedForeignId('employment_staff_id');
            $table->dropColumn('employment_ended_at');
            $table->uuid('receipt_finality_event_id')->nullable(false)->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sales_commission_hold_resolutions ADD CONSTRAINT sales_commission_hold_resolution_kind CHECK (resolution_kind = 'failed_finality_no_entitlement')");
        }
    }
};
