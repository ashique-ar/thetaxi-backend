<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payment_schedules', function (Blueprint $table) {
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('schedule_kind', 30)->default('custom');
            $table->decimal('source_amount', 20, 4)->nullable();
            $table->string('source_currency', 3)->nullable();
            $table->decimal('lkr_amount', 20, 4)->nullable();
            $table->boolean('is_collection_target_eligible')->default(true);
            $table->foreignUuid('collection_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->unsignedInteger('revision_number')->default(1);
            $table->timestamp('superseded_at')->nullable();
            $table->foreignUuid('superseded_by_revision_id')->nullable();
        });

        Schema::create('booking_payment_schedule_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->timestamp('effective_at');
            $table->json('before_snapshot');
            $table->json('after_snapshot');
            $table->text('reason');
            $table->string('idempotency_key', 160);
            $table->char('request_payload_checksum', 64);
            $table->foreignUuid('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();
            $table->unique(['booking_id', 'revision_number'], 'booking_schedule_revision_number_unique');
            $table->unique(['booking_id', 'idempotency_key'], 'booking_schedule_revision_idempotency_unique');
        });
        Schema::table('booking_payment_schedules', function (Blueprint $table) {
            $table->foreign('superseded_by_revision_id', 'booking_schedule_superseding_revision_fk')
                ->references('id')->on('booking_payment_schedule_revisions')->restrictOnDelete();
        });

        Schema::create('booking_collection_work_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignUuid('booking_payment_schedule_id')->constrained('booking_payment_schedules')->restrictOnDelete();
            $table->foreignUuid('assigned_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->string('work_type', 30);
            $table->string('status', 30)->default('open');
            $table->timestamp('due_at');
            $table->unsignedSmallInteger('reminder_offset_days')->default(3);
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->timestamps();
            $table->index(['assigned_sales_profile_id', 'status', 'due_at'], 'collection_work_assignee_due_idx');
        });

        Schema::create('booking_collection_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignUuid('booking_payment_schedule_id')->nullable()->constrained('booking_payment_schedules')->restrictOnDelete();
            $table->foreignUuid('submitted_by_sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->decimal('source_amount', 20, 4);
            $table->string('source_currency', 3);
            $table->string('payment_method', 50);
            $table->string('reference', 160)->nullable();
            $table->timestamp('received_at');
            $table->foreignUuid('evidence_file_id')->nullable()->constrained('domain_evidence_files')->restrictOnDelete();
            $table->string('status', 30)->default('submitted');
            $table->text('staff_notes')->nullable();
            $table->text('verification_notes')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignUuid('booking_payment_receipt_id')->nullable()->constrained('booking_payment_receipts')->restrictOnDelete();
            $table->string('idempotency_key', 160);
            $table->char('request_payload_checksum', 64);
            $table->timestamps();
            $table->unique(['booking_id', 'idempotency_key'], 'booking_collection_submission_idempotency_unique');
            $table->index(['status', 'received_at'], 'booking_collection_submission_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_collection_submissions');
        Schema::dropIfExists('booking_collection_work_items');
        Schema::table('booking_payment_schedules', function (Blueprint $table) {
            $table->dropForeign('booking_schedule_superseding_revision_fk');
        });
        Schema::dropIfExists('booking_payment_schedule_revisions');
        Schema::table('booking_payment_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropConstrainedForeignId('collection_sales_profile_id');
            $table->dropColumn([
                'schedule_kind', 'source_amount', 'source_currency', 'lkr_amount',
                'is_collection_target_eligible', 'revision_number', 'superseded_at', 'superseded_by_revision_id',
            ]);
        });
    }
};
