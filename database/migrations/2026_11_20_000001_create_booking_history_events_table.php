<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_history_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
            $table->uuid('booking_item_id')->nullable();
            $table->string('entity_type', 100);
            $table->string('entity_id', 100);
            $table->string('event_type', 100);
            $table->string('actor_type', 30);
            $table->uuid('actor_user_id')->nullable();
            $table->string('actor_display_snapshot')->nullable();
            $table->string('source', 100)->nullable();
            $table->text('reason')->nullable();
            $table->json('changes')->nullable();
            $table->json('context')->nullable();
            $table->string('correlation_id', 160)->nullable();
            $table->string('idempotency_key', 160)->nullable()->unique();
            $table->timestampTz('occurred_at');
            $table->string('provenance', 30)->default('live');
            $table->timestampsTz();

            $table->index(['booking_id', 'occurred_at', 'id'], 'booking_history_timeline_idx');
            $table->index(['booking_item_id', 'occurred_at'], 'booking_history_item_timeline_idx');
            $table->index(['entity_type', 'entity_id'], 'booking_history_entity_idx');
            $table->index(['event_type', 'occurred_at'], 'booking_history_type_idx');
            $table->index(['actor_user_id', 'occurred_at'], 'booking_history_actor_idx');
        });
    }

    public function down(): void
    {
        throw new LogicException('Booking history is append-only and cannot be dropped by migration rollback.');
    }
};
