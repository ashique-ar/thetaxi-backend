<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_reference_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain', 40);
            $table->string('reference_type', 80);
            $table->string('reference_key', 120);
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->char('content_checksum', 64);
            $table->string('source_uri', 1000)->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['domain', 'reference_type', 'reference_key', 'version'], 'domain_reference_version_unique');
            $table->index(['domain', 'reference_type', 'effective_from', 'effective_until'], 'domain_reference_effective_idx');
        });

        Schema::create('domain_workflow_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain', 40);
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('workflow_type', 80);
            $table->string('subject_type', 160);
            $table->uuid('subject_id');
            $table->string('state', 40);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignUuid('initiated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['domain', 'workflow_type', 'subject_type', 'subject_id'], 'domain_workflow_subject_unique');
            $table->index(['domain', 'state', 'started_at']);
        });

        Schema::create('domain_workflow_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_instance_id')->constrained('domain_workflow_instances')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('from_state', 40)->nullable();
            $table->string('to_state', 40);
            $table->string('action', 80);
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 160);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('transitioned_at');
            $table->timestamps();
            $table->unique(['workflow_instance_id', 'sequence'], 'domain_workflow_transition_sequence_unique');
            $table->unique(['workflow_instance_id', 'idempotency_key'], 'domain_workflow_transition_idempotency_unique');
        });

        Schema::create('domain_idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain', 40);
            $table->string('scope', 120);
            $table->string('idempotency_key', 160);
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('processing');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['domain', 'scope', 'idempotency_key'], 'domain_idempotency_unique');
            $table->index(['status', 'expires_at']);
        });

        Schema::create('domain_outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain', 40);
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('aggregate_type', 120);
            $table->uuid('aggregate_id');
            $table->string('event_type', 160);
            $table->unsignedInteger('event_version');
            $table->unsignedInteger('schema_version');
            $table->json('payload');
            $table->char('payload_checksum', 64);
            $table->string('correlation_id', 160)->nullable();
            $table->string('causation_id', 160)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('available_at');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['domain', 'aggregate_type', 'aggregate_id', 'event_version'], 'domain_outbox_aggregate_version_unique');
            $table->index(['published_at', 'available_at']);
        });

        Schema::create('domain_period_locks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain', 40);
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('period_type', 40);
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->string('state', 20)->default('open');
            $table->unsignedInteger('lock_version')->default(1);
            $table->text('reason')->nullable();
            $table->foreignUuid('locked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignUuid('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();
            $table->unique(['domain', 'company_id', 'period_type', 'period_start', 'period_end'], 'domain_period_lock_unique');
            $table->index(['domain', 'state', 'period_end']);
        });

        Schema::create('domain_transfer_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain', 40);
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('direction', 10);
            $table->string('job_type', 100);
            $table->string('format', 20);
            $table->string('state', 20)->default('queued');
            $table->string('disk', 40)->nullable();
            $table->string('path', 1000)->nullable();
            $table->char('file_checksum', 64)->nullable();
            $table->unsignedBigInteger('input_rows')->default(0);
            $table->unsignedBigInteger('accepted_rows')->default(0);
            $table->unsignedBigInteger('rejected_rows')->default(0);
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_summary')->nullable();
            $table->timestamps();
            $table->index(['domain', 'state', 'queued_at']);
        });

        Schema::create('domain_transfer_job_errors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_job_id')->constrained('domain_transfer_jobs')->cascadeOnDelete();
            $table->unsignedBigInteger('row_number')->nullable();
            $table->string('field_name', 120)->nullable();
            $table->string('error_code', 80);
            $table->text('message');
            $table->char('row_checksum', 64)->nullable();
            $table->timestamps();
            $table->index(['transfer_job_id', 'row_number']);
        });

        Schema::create('domain_evidence_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain', 40);
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('subject_type', 160);
            $table->uuid('subject_id');
            $table->string('evidence_type', 80);
            $table->string('classification', 30);
            $table->string('disk', 40);
            $table->string('path', 1000);
            $table->string('file_name', 255);
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('file_size');
            $table->char('file_checksum', 64);
            $table->unsignedInteger('version')->default(1);
            $table->uuid('supersedes_id')->nullable()->index();
            $table->timestamp('retention_until')->nullable();
            $table->foreignUuid('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['domain', 'subject_type', 'subject_id'], 'domain_evidence_subject_idx');
        });

        Schema::create('domain_audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain', 40);
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('subject_type', 160);
            $table->uuid('subject_id');
            $table->string('event_type', 160);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_type', 40);
            $table->string('correlation_id', 160)->nullable();
            $table->string('source_ip', 64)->nullable();
            $table->char('before_checksum', 64)->nullable();
            $table->char('after_checksum', 64)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['domain', 'subject_type', 'subject_id', 'occurred_at'], 'domain_audit_subject_idx');
            $table->index(['actor_user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_audit_events');
        Schema::dropIfExists('domain_evidence_files');
        Schema::dropIfExists('domain_transfer_job_errors');
        Schema::dropIfExists('domain_transfer_jobs');
        Schema::dropIfExists('domain_period_locks');
        Schema::dropIfExists('domain_outbox_events');
        Schema::dropIfExists('domain_idempotency_keys');
        Schema::dropIfExists('domain_workflow_transitions');
        Schema::dropIfExists('domain_workflow_instances');
        Schema::dropIfExists('domain_reference_versions');
    }
};
