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
            $table->unsignedInteger('event_version')->default(1)->after('status');
            $table->timestampTz('snoozed_until')->nullable()->after('assigned_to');
            $table->unsignedInteger('escalation_level')->default(0)->after('snoozed_until');
            $table->timestampTz('escalated_at')->nullable()->after('escalation_level');
            $table->timestampTz('last_action_at')->nullable()->after('escalated_at');
            $table->index(['company_id', 'status', 'snoozed_until'], 'sales_alert_action_queue_idx');
        });

        Schema::create('sales_performance_alert_action_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('alert_id')->constrained('sales_performance_alerts')->restrictOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->unsignedInteger('from_version');
            $table->unsignedInteger('to_version');
            $table->text('reason');
            $table->timestampTz('snoozed_until')->nullable();
            $table->unsignedInteger('escalation_level')->nullable();
            $table->char('request_payload_checksum', 64);
            $table->string('idempotency_key', 160);
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key'], 'sales_alert_action_idempotency_unique');
            $table->unique(['alert_id', 'to_version'], 'sales_alert_action_version_unique');
            $table->index(['company_id', 'occurred_at'], 'sales_alert_action_company_time_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_performance_alert_action_events')
            && (DB::table('sales_performance_alert_action_events')->exists()
                || DB::table('sales_performance_alerts')->where('event_version', '>', 1)->exists()
                || DB::table('sales_performance_alerts')->whereNotNull('snoozed_until')->exists()
                || DB::table('sales_performance_alerts')->where('escalation_level', '>', 0)->exists())) {
            throw new RuntimeException('Rollback refused: export and reconcile immutable Sales alert action evidence first.');
        }

        Schema::dropIfExists('sales_performance_alert_action_events');
        Schema::table('sales_performance_alerts', function (Blueprint $table) {
            $table->dropIndex('sales_alert_action_queue_idx');
            $table->dropColumn(['event_version', 'snoozed_until', 'escalation_level', 'escalated_at', 'last_action_at']);
        });
    }
};
