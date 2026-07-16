<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_customer_mobile_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id')->index();
            $table->uuid('booking_item_id')->index();
            $table->uuid('customer_id')->index();
            $table->uuid('user_id')->index();
            $table->uuid('client_event_id');
            $table->string('event_type', 32);
            $table->timestamp('occurred_at');
            $table->timestamp('actual_start_time')->nullable();
            $table->timestamp('actual_return_time')->nullable();
            $table->decimal('distance_km', 12, 3)->nullable();
            $table->unsignedInteger('waiting_minutes')->nullable();
            $table->char('payload_hash', 64);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // A mobile retry with the same client event is a read-only replay.
            // A different payload under the same key is rejected by the service.
            $table->unique(
                ['booking_item_id', 'client_event_id'],
                'booking_customer_activity_item_event_unique'
            );
            $table->index(
                ['booking_item_id', 'occurred_at'],
                'booking_customer_activity_item_occurred_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_customer_mobile_activities');
    }
};
