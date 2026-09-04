<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_kpi_snapshots', function (Blueprint $table) {
            $table->foreignUuid('period_lock_id')->nullable()->after('company_id')
                ->constrained('domain_period_locks')->restrictOnDelete();
            $table->foreignUuid('supersedes_snapshot_id')->nullable()->after('period_lock_id')
                ->constrained('sales_kpi_snapshots')->restrictOnDelete();
            $table->string('generation_kind', 20)->default('initial_close')->after('status');
            $table->json('source_reconciliation_snapshot')->nullable()->after('ranking_policy_snapshot');
            $table->char('source_reconciliation_checksum', 64)->nullable()->after('source_reconciliation_snapshot');
            $table->index(['period_lock_id', 'status'], 'sales_kpi_period_lock_status_idx');
        });

        Schema::create('sales_period_close_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('period_lock_id')->constrained('domain_period_locks')->restrictOnDelete();
            $table->foreignUuid('snapshot_id')->nullable()->constrained('sales_kpi_snapshots')->restrictOnDelete();
            $table->string('action', 20);
            $table->string('from_state', 20);
            $table->string('to_state', 20);
            $table->unsignedInteger('lock_version');
            $table->text('reason');
            $table->json('reconciliation_snapshot')->nullable();
            $table->char('reconciliation_checksum', 64)->nullable();
            $table->char('preview_checksum', 64)->nullable();
            $table->char('request_payload_checksum', 64);
            $table->string('idempotency_key', 160);
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key'], 'sales_period_close_idempotency_unique');
            $table->unique(['period_lock_id', 'lock_version'], 'sales_period_close_lock_version_unique');
            $table->index(['company_id', 'occurred_at'], 'sales_period_close_company_time_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_period_close_events')
            && (DB::table('sales_period_close_events')->exists()
                || DB::table('sales_kpi_snapshots')->whereNotNull('period_lock_id')->exists())) {
            throw new RuntimeException('Rollback refused: export and reconcile immutable Sales period-close evidence first.');
        }

        Schema::dropIfExists('sales_period_close_events');
        Schema::table('sales_kpi_snapshots', function (Blueprint $table) {
            $table->dropIndex('sales_kpi_period_lock_status_idx');
            $table->dropConstrainedForeignId('supersedes_snapshot_id');
            $table->dropConstrainedForeignId('period_lock_id');
            $table->dropColumn(['generation_kind', 'source_reconciliation_snapshot', 'source_reconciliation_checksum']);
        });
    }
};
