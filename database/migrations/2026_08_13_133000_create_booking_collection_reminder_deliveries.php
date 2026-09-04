<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_collection_reminder_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignUuid('booking_payment_schedule_id')->constrained('booking_payment_schedules')->restrictOnDelete();
            $table->foreignUuid('booking_collection_work_item_id')->nullable()->constrained('booking_collection_work_items')->restrictOnDelete();
            $table->foreignUuid('assigned_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reminder_type', 30);
            $table->string('channel', 30);
            $table->date('schedule_due_date');
            $table->decimal('outstanding_amount', 20, 4);
            $table->string('source_currency', 3);
            $table->string('status', 30);
            $table->uuid('database_notification_id')->nullable()->unique();
            $table->timestamp('dispatched_at')->nullable();
            $table->string('idempotency_key', 190)->unique();
            $table->char('payload_checksum', 64);
            $table->timestamps();
            $table->index(['recipient_user_id', 'status', 'schedule_due_date'], 'collection_reminder_recipient_status_idx');
            $table->index(['booking_payment_schedule_id', 'reminder_type'], 'collection_reminder_schedule_type_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('booking_collection_reminder_deliveries')
            && DB::table('booking_collection_reminder_deliveries')->exists()) {
            throw new RuntimeException('Refusing to drop immutable booking collection reminder delivery evidence.');
        }

        Schema::dropIfExists('booking_collection_reminder_deliveries');
    }
};
