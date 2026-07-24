<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->boolean('collection_commission_enabled')->default(false)->after('staff_type');
            $table->decimal('collection_commission_rate', 5, 2)->default(0)->after('collection_commission_enabled');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignUuid('commission_owner_staff_id')->nullable()->after('created_user_id')
                ->constrained('staff')->nullOnDelete();
            $table->string('payment_schedule_frequency', 30)->nullable();
            $table->date('payment_schedule_start_date')->nullable();
            $table->decimal('payment_schedule_installment_amount', 12, 2)->nullable();
            $table->unsignedSmallInteger('payment_schedule_reminder_days')->default(3);
        });

        Schema::table('booking_payment_receipts', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->unique();
        });

        Schema::create('collection_commission_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('payout_number')->unique();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('total_amount', 12, 2);
            $table->string('status', 30)->default('paid');
            $table->timestamp('paid_at');
            $table->string('payment_reference')->unique();
            $table->text('notes')->nullable();
            $table->foreignUuid('paid_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('booking_collection_commissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignUuid('booking_payment_receipt_id')->unique()
                ->constrained('booking_payment_receipts')->cascadeOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->decimal('receipt_amount', 12, 2);
            $table->decimal('eligible_amount', 12, 2);
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->string('status', 30)->default('earned');
            $table->string('ineligibility_reason')->nullable();
            $table->timestamp('earned_at');
            $table->timestamp('paid_at')->nullable();
            $table->foreignUuid('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('payout_id')->nullable()->constrained('collection_commission_payouts')->nullOnDelete();
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['staff_id', 'earned_at'], 'collection_commission_staff_date_idx');
            $table->index(['status', 'earned_at'], 'collection_commission_status_date_idx');
        });

        Schema::create('booking_payment_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('label')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->string('status', 30)->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['booking_id', 'sequence'], 'booking_payment_schedule_sequence_unique');
            $table->index(['booking_id', 'due_date'], 'booking_payment_schedule_due_idx');
        });

        Schema::create('booking_payment_schedule_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_payment_schedule_id')->constrained('booking_payment_schedules')->cascadeOnDelete();
            $table->foreignUuid('booking_payment_receipt_id')->constrained('booking_payment_receipts')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamp('allocated_at');
            $table->foreignUuid('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(
                ['booking_payment_schedule_id', 'booking_payment_receipt_id'],
                'booking_payment_schedule_receipt_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payment_schedule_allocations');
        Schema::dropIfExists('booking_payment_schedules');
        Schema::dropIfExists('booking_collection_commissions');
        Schema::dropIfExists('collection_commission_payouts');
        Schema::table('booking_payment_receipts', fn (Blueprint $table) => $table->dropColumn('idempotency_key'));
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_owner_staff_id');
            $table->dropColumn([
                'payment_schedule_frequency', 'payment_schedule_start_date',
                'payment_schedule_installment_amount', 'payment_schedule_reminder_days',
            ]);
        });
        Schema::table('staff', fn (Blueprint $table) => $table->dropColumn([
            'collection_commission_enabled', 'collection_commission_rate',
        ]));
    }
};
