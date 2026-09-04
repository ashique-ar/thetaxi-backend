<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_commission_recovery_cases', function (Blueprint $table) {
            $table->string('entitlement_source_type', 50)->nullable()->after('commission_decision_id');
            $table->uuid('entitlement_source_id')->nullable()->after('entitlement_source_type');
            $table->decimal('entitlement_amount_lkr', 20, 4)->nullable()->after('entitlement_source_id');
            $table->timestampTz('entitlement_effective_at')->nullable()->after('entitlement_amount_lkr');
            $table->index(
                ['entitlement_source_type', 'entitlement_source_id'],
                'commission_recovery_entitlement_source_idx',
            );
        });

        DB::table('sales_commission_recovery_cases as recovery')
            ->join('sales_commission_decisions as decision', 'decision.id', '=', 'recovery.commission_decision_id')
            ->select([
                'recovery.id', 'decision.id as decision_id', 'decision.commission_amount_lkr',
                'decision.earned_at', 'decision.decision_at', 'decision.status as decision_status',
                'recovery.original_commission_amount_lkr',
            ])
            ->orderBy('recovery.id')
            ->each(function ($row): void {
                if ($row->decision_status !== 'earned') {
                    throw new RuntimeException('Migration blocked: a legacy recovery is not linked to an actual earned entitlement.');
                }
                DB::table('sales_commission_recovery_cases')->where('id', $row->id)->update([
                    'entitlement_source_type' => 'commission_decision',
                    'entitlement_source_id' => $row->decision_id,
                    'entitlement_amount_lkr' => $row->commission_amount_lkr ?? $row->original_commission_amount_lkr,
                    'entitlement_effective_at' => $row->earned_at ?? $row->decision_at,
                ]);
            });

        if (DB::table('sales_commission_recovery_cases')->where(function ($query) {
            $query->whereNull('entitlement_source_type')
                ->orWhereNull('entitlement_source_id')
                ->orWhereNull('entitlement_amount_lkr')
                ->orWhere('entitlement_amount_lkr', '<=', 0)
                ->orWhereNull('entitlement_effective_at')
                ->orWhereNull('beneficiary_staff_id')
                ->orWhereNull('beneficiary_sales_profile_id');
        })->exists()) {
            throw new RuntimeException('Migration blocked: reconcile legacy commission recovery entitlement-source evidence before retrying.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sales_commission_recovery_cases ADD CONSTRAINT commission_recovery_entitlement_source_check CHECK (entitlement_source_type IN ('commission_decision', 'commission_hold_release', 'commission_hold_adjustment'))");
            DB::statement('ALTER TABLE sales_commission_recovery_cases ADD CONSTRAINT commission_recovery_entitlement_amount_check CHECK (entitlement_amount_lkr > 0)');
            DB::statement('ALTER TABLE sales_commission_recovery_cases ALTER COLUMN entitlement_source_type SET NOT NULL');
            DB::statement('ALTER TABLE sales_commission_recovery_cases ALTER COLUMN entitlement_source_id SET NOT NULL');
            DB::statement('ALTER TABLE sales_commission_recovery_cases ALTER COLUMN entitlement_amount_lkr SET NOT NULL');
            DB::statement('ALTER TABLE sales_commission_recovery_cases ALTER COLUMN entitlement_effective_at SET NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::table('sales_commission_recovery_cases')->whereNotNull('entitlement_source_id')->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile frozen commission recovery entitlement-source evidence first.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_commission_recovery_cases DROP CONSTRAINT IF EXISTS commission_recovery_entitlement_amount_check');
            DB::statement('ALTER TABLE sales_commission_recovery_cases DROP CONSTRAINT IF EXISTS commission_recovery_entitlement_source_check');
        }
        Schema::table('sales_commission_recovery_cases', function (Blueprint $table) {
            $table->dropIndex('commission_recovery_entitlement_source_idx');
            $table->dropColumn([
                'entitlement_source_type', 'entitlement_source_id', 'entitlement_amount_lkr',
                'entitlement_effective_at',
            ]);
        });
    }
};
