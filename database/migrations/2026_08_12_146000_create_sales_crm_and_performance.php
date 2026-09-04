<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_opportunities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('owner_sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('inquiry_id')->nullable()->constrained('inquiries')->restrictOnDelete();
            $table->foreignUuid('source_phone_call_id')->nullable()->constrained('phone_calls')->restrictOnDelete();
            $table->string('opportunity_number', 100)->unique();
            $table->string('name', 255);
            $table->string('prospect_name', 255)->nullable();
            $table->string('prospect_company', 255)->nullable();
            $table->string('prospect_email', 255)->nullable();
            $table->string('prospect_phone', 80)->nullable();
            $table->string('source', 80);
            $table->string('campaign', 160)->nullable();
            $table->string('referral', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('stage', 30)->default('new');
            $table->decimal('expected_value_source', 20, 4)->default(0);
            $table->string('source_currency', 3)->default('LKR');
            $table->decimal('expected_value_lkr', 20, 4)->nullable();
            $table->unsignedTinyInteger('probability_percent')->default(0);
            $table->date('expected_close_date')->nullable();
            $table->json('services')->nullable();
            $table->text('customer_needs')->nullable();
            $table->text('competitor_notes')->nullable();
            $table->text('next_action')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->string('confidentiality', 30)->default('team');
            $table->string('lost_reason_code', 80)->nullable();
            $table->text('lost_reason')->nullable();
            $table->foreignUuid('won_booking_id')->nullable()->unique()->constrained('bookings')->restrictOnDelete();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('state_version')->default(1);
            $table->foreignUuid('created_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'owner_sales_profile_id', 'stage'], 'sales_opportunity_owner_stage_idx');
            $table->index(['company_id', 'expected_close_date', 'stage'], 'sales_opportunity_forecast_idx');
        });

        Schema::create('sales_opportunity_stage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('opportunity_id')->constrained('sales_opportunities')->restrictOnDelete();
            $table->string('from_stage', 30)->nullable();
            $table->string('to_stage', 30);
            $table->foreignUuid('from_owner_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('to_owner_sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->string('reason_code', 80)->nullable();
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->json('snapshot');
            $table->timestamps();
            $table->index(['opportunity_id', 'occurred_at']);
        });

        Schema::create('sales_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('opportunity_id')->nullable()->constrained('sales_opportunities')->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('inquiry_id')->nullable()->constrained('inquiries')->restrictOnDelete();
            $table->foreignUuid('booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->foreignUuid('phone_call_id')->nullable()->constrained('phone_calls')->restrictOnDelete();
            $table->foreignUuid('booking_activity_id')->nullable()->constrained('booking_activities')->restrictOnDelete();
            $table->string('activity_type', 40);
            $table->string('direction', 20)->nullable();
            $table->string('subject', 255);
            $table->text('notes')->nullable();
            $table->string('outcome', 80)->nullable();
            $table->text('next_action')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->string('source_system', 60)->default('manual');
            $table->string('source_reference', 160)->nullable();
            $table->foreignUuid('evidence_file_id')->nullable()->constrained('domain_evidence_files')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->foreignUuid('created_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['source_system', 'source_reference'], 'sales_activity_source_unique');
            $table->index(['company_id', 'sales_profile_id', 'occurred_at'], 'sales_activity_profile_date_idx');
        });

        Schema::create('sales_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('owner_sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('opportunity_id')->nullable()->constrained('sales_opportunities')->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('inquiry_id')->nullable()->constrained('inquiries')->restrictOnDelete();
            $table->foreignUuid('booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('open');
            $table->timestamp('due_at');
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('reminder_dispatched_at')->nullable();
            $table->timestamp('escalate_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('state_version')->default(1);
            $table->foreignUuid('created_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'owner_sales_profile_id', 'status', 'due_at'], 'sales_task_work_queue_idx');
        });

        Schema::create('sales_task_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('sales_tasks')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignUuid('from_owner_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('to_owner_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignUuid('sales_opportunity_id')->nullable()->after('commission_owner_staff_id')
                ->constrained('sales_opportunities')->restrictOnDelete();
        });

        Schema::create('sales_target_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('new_sales_target_lkr', 20, 4)->nullable();
            $table->decimal('eligible_collections_target_lkr', 20, 4)->nullable();
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('version');
            $table->text('reason')->nullable();
            $table->foreignUuid('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('prepared_at');
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->char('payload_checksum', 64);
            $table->timestamps();
            $table->unique(['sales_profile_id', 'period_start', 'period_end', 'version'], 'sales_target_period_version_unique');
            $table->index(['company_id', 'period_start', 'period_end', 'status'], 'sales_target_period_status_idx');
        });

        Schema::create('sales_metric_facts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->string('metric_type', 60);
            $table->string('business_classification', 40)->nullable();
            $table->decimal('quantity', 20, 4)->default(0);
            $table->decimal('amount_lkr', 20, 4)->default(0);
            $table->date('occurred_on');
            $table->timestamp('occurred_at');
            $table->string('source_type', 80);
            $table->uuid('source_id');
            $table->string('source_event', 80);
            $table->json('dimensions')->nullable();
            $table->char('fact_checksum', 64);
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'source_event', 'sales_profile_id'], 'sales_metric_source_event_unique');
            $table->index(['company_id', 'sales_profile_id', 'metric_type', 'occurred_on'], 'sales_metric_profile_period_idx');
        });

        Schema::create('sales_kpi_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('period_type', 20);
            $table->timestamp('cutoff_at');
            $table->string('status', 30)->default('frozen');
            $table->unsignedInteger('version')->default(1);
            $table->json('ranking_policy_snapshot');
            $table->string('generation_idempotency_key', 160)->unique();
            $table->char('generation_payload_checksum', 64);
            $table->char('snapshot_checksum', 64);
            $table->foreignUuid('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->unique(['company_id', 'period_start', 'period_end', 'version'], 'sales_kpi_snapshot_period_version_unique');
        });

        Schema::create('sales_kpi_snapshot_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('snapshot_id')->constrained('sales_kpi_snapshots')->restrictOnDelete();
            $table->foreignUuid('sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('staff_code', 100);
            $table->decimal('new_sales_target_lkr', 20, 4)->nullable();
            $table->decimal('collection_target_lkr', 20, 4)->nullable();
            $table->decimal('new_sales_lkr', 20, 4)->default(0);
            $table->decimal('new_booking_collections_lkr', 20, 4)->default(0);
            $table->decimal('existing_booking_collections_lkr', 20, 4)->default(0);
            $table->decimal('eligible_collections_lkr', 20, 4)->default(0);
            $table->decimal('commission_new_business_lkr', 20, 4)->default(0);
            $table->decimal('commission_existing_business_lkr', 20, 4)->default(0);
            $table->unsignedInteger('new_bookings_count')->default(0);
            $table->unsignedInteger('new_customers_count')->default(0);
            $table->unsignedInteger('activities_count')->default(0);
            $table->unsignedInteger('overdue_collections_count')->default(0);
            $table->decimal('overdue_collections_lkr', 20, 4)->default(0);
            $table->decimal('new_sales_achievement_percent', 12, 4)->nullable();
            $table->decimal('collection_achievement_percent', 12, 4)->nullable();
            $table->unsignedInteger('display_rank')->nullable();
            $table->json('metric_snapshot');
            $table->char('row_checksum', 64);
            $table->timestamps();
            $table->unique(['snapshot_id', 'sales_profile_id'], 'sales_kpi_snapshot_profile_unique');
        });

        Schema::create('sales_alert_policy_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 30)->default('draft');
            $table->json('rules');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->foreignUuid('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->char('rules_checksum', 64);
            $table->timestamps();
            $table->unique(['company_id', 'version']);
        });

        Schema::create('sales_performance_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('snapshot_id')->constrained('sales_kpi_snapshots')->restrictOnDelete();
            $table->foreignUuid('policy_version_id')->nullable()->constrained('sales_alert_policy_versions')->restrictOnDelete();
            $table->string('alert_type', 60);
            $table->string('severity', 20);
            $table->string('status', 30)->default('open');
            $table->text('explanation');
            $table->json('evidence_snapshot');
            $table->timestamp('detected_at');
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->string('deduplication_key', 200)->unique();
            $table->timestamps();
            $table->index(['company_id', 'status', 'severity', 'detected_at'], 'sales_performance_alert_queue_idx');
        });

        Schema::create('sales_performance_alert_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('alert_id')->constrained('sales_performance_alerts')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('note')->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_performance_alert_events');
        Schema::dropIfExists('sales_performance_alerts');
        Schema::dropIfExists('sales_alert_policy_versions');
        Schema::dropIfExists('sales_kpi_snapshot_rows');
        Schema::dropIfExists('sales_kpi_snapshots');
        Schema::dropIfExists('sales_metric_facts');
        Schema::dropIfExists('sales_target_versions');
        Schema::table('bookings', fn (Blueprint $table) => $table->dropConstrainedForeignId('sales_opportunity_id'));
        Schema::dropIfExists('sales_task_events');
        Schema::dropIfExists('sales_tasks');
        Schema::dropIfExists('sales_activities');
        Schema::dropIfExists('sales_opportunity_stage_events');
        Schema::dropIfExists('sales_opportunities');
    }
};
