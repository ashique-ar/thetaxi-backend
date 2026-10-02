<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_work_calendars', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 80); $table->string('name'); $table->string('timezone', 80); $table->json('weekly_working_days');
            $table->date('effective_from'); $table->date('effective_until')->nullable(); $table->string('status', 30)->default('draft');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete(); $table->timestamps();
            $table->unique(['company_id', 'code', 'effective_from']);
        });
        Schema::create('hr_work_calendar_days', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('calendar_id')->constrained('hr_work_calendars')->restrictOnDelete();
            $table->date('calendar_date'); $table->string('day_type', 30); $table->string('name')->nullable(); $table->boolean('paid')->default(true);
            $table->timestamps(); $table->unique(['calendar_id', 'calendar_date']);
        });
        Schema::create('hr_shift_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 80); $table->string('name'); $table->time('start_time'); $table->time('end_time'); $table->boolean('ends_next_day')->default(false);
            $table->unsignedSmallInteger('unpaid_break_minutes')->default(0); $table->unsignedSmallInteger('grace_in_minutes')->default(0); $table->unsignedSmallInteger('grace_out_minutes')->default(0);
            $table->unsignedSmallInteger('minimum_half_day_minutes')->default(240); $table->unsignedSmallInteger('minimum_full_day_minutes')->default(480);
            $table->string('timezone', 80); $table->date('effective_from'); $table->date('effective_until')->nullable(); $table->string('status', 30)->default('draft');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete(); $table->timestamps(); $table->unique(['company_id', 'code', 'effective_from']);
        });
        Schema::create('hr_attendance_policies', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $table->string('code', 80); $table->string('name');
            $table->json('rules'); $table->date('effective_from'); $table->date('effective_until')->nullable(); $table->string('status', 30)->default('draft');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete(); $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable(); $table->timestamps(); $table->unique(['company_id', 'code', 'effective_from']);
        });
        Schema::create('hr_roster_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('calendar_id')->constrained('hr_work_calendars')->restrictOnDelete(); $table->foreignUuid('shift_id')->constrained('hr_shift_definitions')->restrictOnDelete();
            $table->foreignUuid('policy_id')->constrained('hr_attendance_policies')->restrictOnDelete(); $table->date('effective_from'); $table->date('effective_until')->nullable();
            $table->string('reason', 500); $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete(); $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable(); $table->timestamps(); $table->index(['staff_id', 'effective_from', 'effective_until']);
        });
        Schema::create('hr_attendance_periods', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $table->date('period_start'); $table->date('period_end');
            $table->string('timezone', 80); $table->string('status', 30)->default('open'); $table->unsignedInteger('version')->default(1);
            $table->timestamp('reviewed_at')->nullable(); $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('locked_at')->nullable(); $table->foreignUuid('locked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('state_reason')->nullable(); $table->timestamps(); $table->unique(['company_id', 'period_start', 'period_end']);
        });
        Schema::create('hr_attendance_period_events', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('period_id')->constrained('hr_attendance_periods')->restrictOnDelete(); $table->string('event_type', 40);
            $table->string('from_status', 30); $table->string('to_status', 30); $table->text('reason'); $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at'); $table->unsignedInteger('version'); $table->timestamps();
        });
        Schema::create('hr_attendance_daily_results', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->date('work_date'); $table->unsignedInteger('result_version'); $table->foreignUuid('supersedes_id')->nullable();
            $table->foreignUuid('roster_assignment_id')->nullable()->constrained('hr_roster_assignments')->restrictOnDelete(); $table->foreignUuid('period_id')->nullable()->constrained('hr_attendance_periods')->restrictOnDelete();
            $table->string('day_status', 40); $table->timestampTz('scheduled_start_at')->nullable(); $table->timestampTz('scheduled_end_at')->nullable();
            $table->timestampTz('first_in_at')->nullable(); $table->timestampTz('last_out_at')->nullable(); $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0); $table->unsignedInteger('early_leave_minutes')->default(0); $table->unsignedInteger('payable_minutes')->default(0);
            $table->string('source_kind', 30); $table->timestamp('calculated_at'); $table->foreignUuid('calculated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('input_checksum', 64); $table->char('result_checksum', 64); $table->json('rule_snapshot'); $table->timestamps();
            $table->unique(['staff_id', 'work_date', 'result_version']); $table->index(['company_id', 'work_date', 'day_status']);
        });
        // Postgres cannot resolve a self-referencing foreign key added inside the
        // same Schema::create(); a separate Schema::table() call after creation
        // avoids the ordering issue.
        Schema::table('hr_attendance_daily_results', function (Blueprint $table) {
            $table->foreign('supersedes_id')->references('id')->on('hr_attendance_daily_results')->restrictOnDelete();
        });
        Schema::create('hr_attendance_daily_result_sources', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('daily_result_id')->constrained('hr_attendance_daily_results')->restrictOnDelete();
            $table->foreignUuid('raw_event_id')->constrained('hr_attendance_raw_events')->restrictOnDelete(); $table->string('role', 30); $table->timestamps();
            $table->unique(['daily_result_id', 'raw_event_id']);
        });
        Schema::create('hr_attendance_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('daily_result_id')->constrained('hr_attendance_daily_results')->restrictOnDelete(); $table->string('exception_type', 60); $table->string('severity', 20);
            $table->string('status', 30)->default('open'); $table->json('evidence'); $table->timestamp('resolved_at')->nullable(); $table->foreignUuid('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution_note')->nullable(); $table->timestamps(); $table->index(['company_id', 'status', 'severity']);
        });
        Schema::create('hr_attendance_correction_requests', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->date('work_date'); $table->foreignUuid('current_result_id')->nullable()->constrained('hr_attendance_daily_results')->restrictOnDelete();
            $table->string('correction_type', 50); $table->json('requested_values'); $table->text('reason'); $table->json('evidence_references')->nullable();
            $table->string('status', 30)->default('pending_approval'); $table->string('idempotency_key', 160)->unique(); $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->char('request_checksum', 64);
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete(); $table->timestamp('decided_at')->nullable(); $table->text('decision_note')->nullable();
            $table->foreignUuid('result_id')->nullable()->constrained('hr_attendance_daily_results')->restrictOnDelete(); $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['hr_attendance_correction_requests','hr_attendance_exceptions','hr_attendance_daily_result_sources','hr_attendance_daily_results','hr_attendance_period_events','hr_attendance_periods','hr_roster_assignments','hr_attendance_policies','hr_shift_definitions','hr_work_calendar_days','hr_work_calendars'] as $table) Schema::dropIfExists($table);
    }
};
