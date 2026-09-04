<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_performance_alerts', function (Blueprint $table) {
            $table->json('policy_contract_snapshot')->nullable()->after('evidence_snapshot');
            $table->json('threshold_snapshot')->nullable()->after('policy_contract_snapshot');
            $table->json('comparison_snapshot')->nullable()->after('threshold_snapshot');
            $table->char('evaluation_checksum', 64)->nullable()->after('comparison_snapshot');
        });

        Schema::create('sales_alert_evaluation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('snapshot_id')->constrained('sales_kpi_snapshots')->restrictOnDelete();
            $table->foreignUuid('policy_version_id')->constrained('sales_alert_policy_versions')->restrictOnDelete();
            $table->timestampTz('scheduled_for');
            $table->timestampTz('evaluated_at');
            $table->string('status', 30);
            $table->unsignedInteger('profile_count');
            $table->unsignedInteger('alert_count');
            $table->unsignedInteger('suppressed_rule_count');
            $table->json('result_snapshot');
            $table->char('result_checksum', 64);
            $table->string('idempotency_key', 160);
            $table->timestamps();
            $table->unique(['snapshot_id', 'policy_version_id'], 'sales_alert_evaluation_snapshot_policy_unique');
            $table->unique(['company_id', 'idempotency_key'], 'sales_alert_evaluation_idempotency_unique');
            $table->index(['company_id', 'evaluated_at'], 'sales_alert_evaluation_company_time_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_alert_evaluation_runs')
            && (DB::table('sales_alert_evaluation_runs')->exists()
                || DB::table('sales_performance_alerts')->whereNotNull('evaluation_checksum')->exists())) {
            throw new RuntimeException('Rollback refused: export and reconcile immutable Sales alert evaluation evidence first.');
        }

        Schema::dropIfExists('sales_alert_evaluation_runs');
        Schema::table('sales_performance_alerts', function (Blueprint $table) {
            $table->dropColumn(['policy_contract_snapshot', 'threshold_snapshot', 'comparison_snapshot', 'evaluation_checksum']);
        });
    }
};
