<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_collection_work_item_follow_ups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_collection_work_item_id')->constrained('booking_collection_work_items')->restrictOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignUuid('assigned_sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->date('promised_payment_date')->nullable();
            $table->timestamp('next_follow_up_at');
            $table->text('notes')->nullable();
            $table->string('change_reason', 1000)->nullable();
            $table->string('idempotency_key', 160);
            $table->char('request_payload_checksum', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['booking_collection_work_item_id', 'idempotency_key'], 'collection_follow_up_idempotency_unique');
            $table->index(['booking_collection_work_item_id', 'occurred_at'], 'collection_follow_up_item_time_idx');
            $table->index(['next_follow_up_at', 'booking_collection_work_item_id'], 'collection_follow_up_due_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('booking_collection_work_item_follow_ups')
            && DB::table('booking_collection_work_item_follow_ups')->exists()) {
            throw new \RuntimeException('Refusing to drop immutable booking collection follow-up evidence.');
        }

        Schema::dropIfExists('booking_collection_work_item_follow_ups');
    }
};
