<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_payment_schedule_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('booking_id')->unique()->constrained('bookings')->restrictOnDelete();
            $table->string('contract_basis', 30);
            $table->string('frequency', 30);
            $table->unsignedSmallInteger('frequency_months');
            $table->date('anchor_date');
            $table->decimal('source_amount', 20, 4);
            $table->string('source_currency', 3);
            $table->decimal('lkr_amount', 20, 4)->nullable();
            $table->boolean('is_collection_target_eligible');
            $table->unsignedSmallInteger('reminder_offset_days');
            $table->unsignedSmallInteger('horizon_months');
            $table->string('status', 30);
            $table->unsignedInteger('version');
            $table->unsignedInteger('last_generated_occurrence')->default(0);
            $table->date('last_generated_through')->nullable();
            $table->text('reason');
            $table->string('idempotency_key', 160)->unique();
            $table->char('request_payload_checksum', 64);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'last_generated_through'], 'booking_schedule_rule_extension_idx');
        });

        Schema::create('booking_payment_schedule_rule_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_payment_schedule_rule_id')->constrained('booking_payment_schedule_rules')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('event_type', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->timestamp('effective_at');
            $table->text('reason')->nullable();
            $table->json('metadata');
            $table->string('idempotency_key', 190)->unique();
            $table->char('request_payload_checksum', 64);
            $table->char('event_checksum', 64);
            $table->string('actor_type', 20);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['booking_payment_schedule_rule_id', 'version'], 'booking_schedule_rule_event_version_unique');
        });

        Schema::table('booking_payment_schedules', function (Blueprint $table) {
            $table->foreignUuid('booking_payment_schedule_rule_id')->nullable()
                ->constrained('booking_payment_schedule_rules')->restrictOnDelete();
            $table->unsignedInteger('rule_occurrence_number')->nullable();
            $table->unique(
                ['booking_payment_schedule_rule_id', 'rule_occurrence_number'],
                'booking_schedule_rule_occurrence_unique',
            );
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('booking_payment_schedule_rules') && DB::table('booking_payment_schedule_rules')->exists())
            || (Schema::hasTable('booking_payment_schedules')
                && Schema::hasColumn('booking_payment_schedules', 'booking_payment_schedule_rule_id')
                && DB::table('booking_payment_schedules')->whereNotNull('booking_payment_schedule_rule_id')->exists())) {
            throw new RuntimeException('Refusing to drop rolling payment schedule rule or occurrence evidence.');
        }

        if (Schema::hasTable('booking_payment_schedules')
            && Schema::hasColumn('booking_payment_schedules', 'booking_payment_schedule_rule_id')) {
            Schema::table('booking_payment_schedules', function (Blueprint $table) {
                $table->dropUnique('booking_schedule_rule_occurrence_unique');
                $table->dropConstrainedForeignId('booking_payment_schedule_rule_id');
                $table->dropColumn('rule_occurrence_number');
            });
        }
        Schema::dropIfExists('booking_payment_schedule_rule_events');
        Schema::dropIfExists('booking_payment_schedule_rules');
    }
};
