<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_types', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $t->string('code',80); $t->string('name');
            $t->string('category',40); $t->string('unit',20); $t->boolean('paid')->default(true); $t->boolean('medical_confidential')->default(false);
            $t->date('effective_from'); $t->date('effective_until')->nullable(); $t->string('status',30)->default('draft'); $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['company_id','code','effective_from']);
        });
        Schema::create('hr_leave_policies', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $t->foreignUuid('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $t->string('code',80); $t->unsignedInteger('version'); $t->json('rules'); $t->date('effective_from'); $t->date('effective_until')->nullable(); $t->string('status',30)->default('pending_approval');
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete(); $t->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('approved_at')->nullable(); $t->timestamps();
            $t->unique(['company_id','code','version']); $t->index(['leave_type_id','effective_from','effective_until']);
        });
        Schema::create('hr_leave_policy_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $t->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $t->foreignUuid('policy_id')->constrained('hr_leave_policies')->restrictOnDelete(); $t->date('effective_from'); $t->date('effective_until')->nullable(); $t->string('reason',500);
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete(); $t->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('approved_at')->nullable(); $t->timestamps();
            $t->index(['staff_id','effective_from','effective_until']);
        });
        Schema::create('hr_leave_balance_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $t->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $t->foreignUuid('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete(); $t->string('unit',20); $t->date('opened_at'); $t->date('closed_at')->nullable(); $t->timestamps();
            $t->unique(['staff_id','leave_type_id']);
        });
        Schema::create('hr_leave_requests', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $t->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $t->foreignUuid('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete(); $t->foreignUuid('policy_id')->constrained('hr_leave_policies')->restrictOnDelete();
            $t->date('start_date'); $t->date('end_date'); $t->string('unit',20); $t->integer('requested_minutes'); $t->integer('reserved_minutes'); $t->string('status',30)->default('pending_approval');
            $t->text('reason'); $t->json('calculation_snapshot'); $t->json('coverage_snapshot')->nullable(); $t->text('private_evidence')->nullable(); $t->char('request_checksum',64); $t->string('idempotency_key',160)->unique();
            $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete(); $t->foreignUuid('current_approver_staff_id')->nullable()->constrained('staff')->restrictOnDelete(); $t->unsignedSmallInteger('approval_level')->default(1);
            $t->timestamp('decided_at')->nullable(); $t->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamps(); $t->index(['staff_id','start_date','end_date','status']);
        });
        Schema::create('hr_leave_request_days', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('leave_request_id')->constrained('hr_leave_requests')->restrictOnDelete(); $t->date('leave_date'); $t->integer('minutes'); $t->string('day_kind',30); $t->json('rule_evidence'); $t->timestamps();
            $t->unique(['leave_request_id','leave_date']);
        });
        Schema::create('hr_leave_balance_entries', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('account_id')->constrained('hr_leave_balance_accounts')->restrictOnDelete(); $t->foreignUuid('leave_request_id')->nullable()->constrained('hr_leave_requests')->restrictOnDelete();
            $t->string('entry_type',30); $t->integer('minutes'); $t->date('effective_date'); $t->date('expires_on')->nullable(); $t->string('source_type',80); $t->string('source_id',160)->nullable();
            $t->text('reason'); $t->json('rule_snapshot')->nullable(); $t->char('entry_checksum',64); $t->foreignUuid('posted_by')->constrained('users')->restrictOnDelete(); $t->timestamp('posted_at'); $t->timestamps();
            $t->unique(['source_type','source_id','entry_type']); $t->index(['account_id','effective_date','expires_on']);
        });
        Schema::create('hr_leave_request_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('leave_request_id')->constrained('hr_leave_requests')->restrictOnDelete(); $t->string('event_type',40); $t->string('from_status',30)->nullable(); $t->string('to_status',30);
            $t->text('reason'); $t->json('snapshot'); $t->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete(); $t->timestamp('occurred_at'); $t->timestamps();
        });
        Schema::create('hr_work_request_policies', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $t->string('request_kind',40); $t->string('code',80); $t->unsignedInteger('version'); $t->json('rules');
            $t->date('effective_from'); $t->date('effective_until')->nullable(); $t->string('status',30)->default('pending_approval'); $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('approved_at')->nullable(); $t->timestamps(); $t->unique(['company_id','code','version']);
        });
        Schema::create('hr_work_requests', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $t->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $t->foreignUuid('policy_id')->constrained('hr_work_request_policies')->restrictOnDelete(); $t->string('request_kind',40); $t->timestampTz('starts_at'); $t->timestampTz('ends_at'); $t->integer('requested_minutes');
            $t->string('rate_category',60)->nullable(); $t->string('settlement_kind',30)->nullable(); $t->string('status',30)->default('pending_approval'); $t->text('reason'); $t->json('request_snapshot');
            $t->char('request_checksum',64); $t->string('idempotency_key',160)->unique(); $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete(); $t->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable(); $t->text('decision_note')->nullable(); $t->timestamps(); $t->index(['staff_id','starts_at','ends_at','status']);
        });
        Schema::create('hr_work_request_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('work_request_id')->constrained('hr_work_requests')->restrictOnDelete(); $t->string('event_type',40); $t->string('from_status',30)->nullable(); $t->string('to_status',30);
            $t->text('reason'); $t->json('snapshot'); $t->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete(); $t->timestamp('occurred_at'); $t->timestamps();
        });
        Schema::create('hr_timesheets', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $t->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete(); $t->date('period_start'); $t->date('period_end');
            $t->string('status',30)->default('draft'); $t->unsignedInteger('version')->default(1); $t->char('content_checksum',64)->nullable(); $t->json('reconciliation_snapshot')->nullable(); $t->foreignUuid('submitted_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('submitted_at')->nullable();
            $t->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('decided_at')->nullable(); $t->text('decision_note')->nullable(); $t->timestamps(); $t->unique(['staff_id','period_start','period_end']);
        });
        Schema::create('hr_timesheet_entries', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('timesheet_id')->constrained('hr_timesheets')->cascadeOnDelete(); $t->date('work_date'); $t->timestampTz('started_at')->nullable(); $t->timestampTz('ended_at')->nullable(); $t->integer('minutes');
            $t->string('entry_mode',20); $t->string('cost_centre_code',80)->nullable(); $t->string('project_code',80)->nullable(); $t->uuid('booking_id')->nullable(); $t->string('job_reference',120)->nullable();
            $t->string('activity_code',80); $t->boolean('billable')->default(false); $t->text('notes')->nullable(); $t->char('entry_checksum',64); $t->timestamps(); $t->index(['timesheet_id','work_date']);
        });
        Schema::create('hr_timesheet_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('timesheet_id')->constrained('hr_timesheets')->restrictOnDelete(); $t->string('event_type',40); $t->string('from_status',30)->nullable(); $t->string('to_status',30); $t->unsignedInteger('version');
            $t->text('reason'); $t->char('content_checksum',64)->nullable(); $t->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete(); $t->timestamp('occurred_at'); $t->timestamps();
        });
        Schema::create('hr_payroll_input_facts', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $t->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $t->string('fact_kind',50); $t->date('effective_date'); $t->integer('quantity_minutes')->nullable(); $t->decimal('quantity_units',18,6)->nullable(); $t->string('rate_category',60)->nullable();
            $t->string('source_type',80); $t->uuid('source_id'); $t->string('status',30)->default('staged'); $t->json('source_snapshot'); $t->char('fact_checksum',64); $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['source_type','source_id','fact_kind']); $t->index(['company_id','staff_id','effective_date','status']);
        });
    }

    public function down(): void
    {
        foreach (['hr_payroll_input_facts','hr_timesheet_events','hr_timesheet_entries','hr_timesheets','hr_work_request_events','hr_work_requests','hr_work_request_policies','hr_leave_request_events','hr_leave_balance_entries','hr_leave_request_days','hr_leave_requests','hr_leave_balance_accounts','hr_leave_policy_assignments','hr_leave_policies','hr_leave_types'] as $table) Schema::dropIfExists($table);
    }
};
