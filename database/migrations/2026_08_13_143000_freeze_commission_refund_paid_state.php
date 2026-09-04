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
            $table->unsignedInteger('event_version')->default(1)->after('status');
        });

        Schema::table('sales_commission_recovery_decisions', function (Blueprint $table) {
            $table->string('resolution_disposition', 60)->nullable()->after('waived_recovery_lkr');
            $table->foreignUuid('source_statement_id')->nullable()->after('resolution_disposition')
                ->constrained('sales_commission_statements')->restrictOnDelete();
            $table->foreignUuid('source_statement_line_id')->nullable()->after('source_statement_id')
                ->constrained('sales_commission_statement_lines')->restrictOnDelete();
            $table->string('source_statement_status', 30)->nullable()->after('source_statement_line_id');
            $table->decimal('source_statement_paid_lkr', 20, 4)->nullable()->after('source_statement_status');
            $table->decimal('source_statement_net_payable_lkr', 20, 4)->nullable()->after('source_statement_paid_lkr');
            $table->json('decision_preview_snapshot')->nullable()->after('source_statement_net_payable_lkr');
            $table->char('decision_preview_checksum', 64)->nullable()->after('decision_preview_snapshot');
            $table->index(['resolution_disposition', 'approved_at'], 'commission_recovery_disposition_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sales_commission_recovery_decisions ADD CONSTRAINT commission_recovery_disposition_check CHECK (resolution_disposition IS NULL OR resolution_disposition IN ('unstatemented_liability_adjustment', 'unpaid_statement_liability_adjustment', 'paid_negative_carry_forward', 'post_payment_credit', 'waived_recovery', 'no_change'))");
            DB::statement('ALTER TABLE sales_commission_recovery_decisions ADD CONSTRAINT commission_recovery_statement_snapshot_check CHECK ((source_statement_id IS NULL AND source_statement_line_id IS NULL AND source_statement_status IS NULL AND source_statement_paid_lkr IS NULL AND source_statement_net_payable_lkr IS NULL) OR (source_statement_id IS NOT NULL AND source_statement_line_id IS NOT NULL AND source_statement_status IS NOT NULL AND source_statement_paid_lkr IS NOT NULL AND source_statement_net_payable_lkr IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (DB::table('sales_commission_recovery_decisions')->whereNotNull('decision_preview_checksum')->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile frozen commission refund paid-state decisions first.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_commission_recovery_decisions DROP CONSTRAINT IF EXISTS commission_recovery_statement_snapshot_check');
            DB::statement('ALTER TABLE sales_commission_recovery_decisions DROP CONSTRAINT IF EXISTS commission_recovery_disposition_check');
        }
        Schema::table('sales_commission_recovery_decisions', function (Blueprint $table) {
            $table->dropIndex('commission_recovery_disposition_idx');
            $table->dropConstrainedForeignId('source_statement_line_id');
            $table->dropConstrainedForeignId('source_statement_id');
            $table->dropColumn([
                'resolution_disposition', 'source_statement_status', 'source_statement_paid_lkr',
                'source_statement_net_payable_lkr', 'decision_preview_snapshot', 'decision_preview_checksum',
            ]);
        });
        Schema::table('sales_commission_recovery_cases', function (Blueprint $table) {
            $table->dropColumn('event_version');
        });
    }
};
