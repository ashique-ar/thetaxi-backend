<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vehicle_finance_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('provider_type', 40)->index();
            $table->string('registration_number')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vehicle_leases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignUuid('finance_provider_id')->constrained('vehicle_finance_providers')->restrictOnDelete();
            $table->foreignUuid('owner_id_at_start')->nullable()->constrained('vehicle_owners')->nullOnDelete();
            $table->string('ownership_type_at_start', 40);
            $table->string('lease_number')->unique();
            $table->string('agreement_number')->nullable()->unique();
            $table->string('contract_type', 40)->index();
            $table->string('title_holder')->nullable();
            $table->string('lien_reference')->nullable();
            $table->boolean('ownership_transfer_required')->default(false);
            $table->date('start_date');
            $table->date('end_date');
            $table->date('first_payment_date');
            $table->string('currency', 3)->default('LKR');
            $table->decimal('financed_amount', 14, 2);
            $table->decimal('down_payment', 14, 2)->default(0);
            $table->decimal('refundable_deposit', 14, 2)->default(0);
            $table->decimal('deposit_paid_amount', 14, 2)->default(0);
            $table->date('deposit_paid_date')->nullable();
            $table->string('deposit_payment_reference')->nullable();
            $table->decimal('installment_amount', 14, 2);
            $table->string('payment_frequency', 30)->default('monthly');
            $table->unsignedSmallInteger('installment_count');
            $table->decimal('interest_rate', 7, 4)->default(0);
            $table->decimal('balloon_payment', 14, 2)->default(0);
            $table->unsignedSmallInteger('reminder_days')->default(7);
            $table->string('status', 30)->default('draft')->index();
            $table->string('financial_status', 30)->default('pending')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('financially_settled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('expiry_reminder_sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closure_type', 40)->nullable();
            $table->string('closure_reference')->nullable()->unique();
            $table->foreignUuid('closure_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->text('closure_notes')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['vehicle_id', 'start_date', 'end_date'], 'vehicle_lease_period_idx');
        });

        Schema::create('vehicle_lease_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_lease_id')->constrained('vehicle_leases')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->decimal('principal_amount', 14, 2)->default(0);
            $table->decimal('interest_amount', 14, 2)->default(0);
            $table->decimal('fee_amount', 14, 2)->default(0);
            $table->decimal('amount_due', 14, 2);
            $table->string('status', 30)->default('scheduled')->index();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['vehicle_lease_id', 'sequence'], 'vehicle_lease_schedule_sequence_unique');
            $table->index(['due_date', 'status'], 'vehicle_lease_schedule_due_idx');
        });

        Schema::create('vehicle_lease_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_lease_id')->constrained('vehicle_leases')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_date');
            $table->string('payment_method', 40);
            $table->string('reference')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->string('status', 30)->default('recorded')->index();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignUuid('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['vehicle_lease_id', 'paid_date'], 'vehicle_lease_payment_date_idx');
        });

        Schema::create('vehicle_lease_payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_lease_payment_id')->constrained('vehicle_lease_payments')->restrictOnDelete();
            $table->foreignUuid('vehicle_lease_schedule_id')->constrained('vehicle_lease_schedules')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamp('allocated_at');
            $table->timestamp('reversed_at')->nullable();
            $table->foreignUuid('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['vehicle_lease_payment_id', 'vehicle_lease_schedule_id'], 'vehicle_lease_payment_schedule_unique');
        });

        Schema::create('vehicle_lease_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_lease_id')->unique()->constrained('vehicle_leases')->restrictOnDelete();
            $table->string('release_type', 40);
            $table->timestamp('effective_at');
            $table->unsignedBigInteger('odometer')->nullable();
            $table->string('condition_status', 40)->nullable();
            $table->string('location')->nullable();
            $table->string('released_to')->nullable();
            $table->decimal('outstanding_amount', 14, 2)->default(0);
            $table->decimal('termination_charge', 14, 2)->default(0);
            $table->decimal('deposit_credit', 14, 2)->default(0);
            $table->decimal('net_settlement_amount', 14, 2)->default(0);
            $table->string('settlement_status', 30)->default('pending');
            $table->string('reference')->nullable();
            $table->string('settlement_reference')->nullable()->unique();
            $table->timestamp('settled_at')->nullable();
            $table->foreignUuid('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->text('condition_notes')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('approved_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vehicle_lease_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_lease_id')->constrained('vehicle_leases')->restrictOnDelete();
            $table->string('event_type', 50)->index();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignUuid('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vehicle_lease_id', 'occurred_at'], 'vehicle_lease_event_history_idx');
        });

        Schema::create('vehicle_ownership_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignUuid('vehicle_lease_id')->nullable()->constrained('vehicle_leases')->restrictOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignUuid('from_owner_id')->nullable()->constrained('vehicle_owners')->nullOnDelete();
            $table->foreignUuid('to_owner_id')->nullable()->constrained('vehicle_owners')->nullOnDelete();
            $table->string('from_ownership_type', 40);
            $table->string('to_ownership_type', 40);
            $table->string('transfer_type', 40);
            $table->timestamp('effective_at');
            $table->string('reference')->unique();
            $table->text('notes')->nullable();
            $table->foreignUuid('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vehicle_id', 'effective_at'], 'vehicle_ownership_history_idx');
        });

        $validUserIds = DB::table('users')->pluck('id')->flip();
        $validOwnerIds = DB::table('vehicle_owners')->pluck('id')->flip();

        DB::table('vehicles')
            ->select(['id', 'owner_id', 'ownership_type', 'created_user_id', 'updated_user_id', 'created_at'])
            ->orderBy('id')
            ->chunkById(500, function ($vehicles) use ($validUserIds, $validOwnerIds): void {
                $now = now();
                DB::table('vehicle_ownership_histories')->insert(
                    $vehicles->map(function ($vehicle) use ($now, $validUserIds, $validOwnerIds) {
                        $actorId = $validUserIds->has($vehicle->created_user_id)
                            ? $vehicle->created_user_id
                            : ($validUserIds->has($vehicle->updated_user_id) ? $vehicle->updated_user_id : null);

                        return [
                            'id' => (string) Str::uuid(),
                            'vehicle_id' => $vehicle->id,
                            'vehicle_lease_id' => null,
                            'from_owner_id' => null,
                            'to_owner_id' => $validOwnerIds->has($vehicle->owner_id) ? $vehicle->owner_id : null,
                            'from_ownership_type' => 'unregistered',
                            'to_ownership_type' => $vehicle->ownership_type ?: 'company_owned',
                            'transfer_type' => 'legacy_snapshot',
                            'effective_at' => $vehicle->created_at ?: $now,
                            'reference' => 'INITIAL-' . $vehicle->id,
                            'notes' => 'Ownership snapshot backfilled when finance and leasing management was introduced; earlier transfer history was not available.',
                            'performed_by' => $actorId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    })->all()
                );
            }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_ownership_histories');
        Schema::dropIfExists('vehicle_lease_events');
        Schema::dropIfExists('vehicle_lease_releases');
        Schema::dropIfExists('vehicle_lease_payment_allocations');
        Schema::dropIfExists('vehicle_lease_payments');
        Schema::dropIfExists('vehicle_lease_schedules');
        Schema::dropIfExists('vehicle_leases');
        Schema::dropIfExists('vehicle_finance_providers');
    }
};
