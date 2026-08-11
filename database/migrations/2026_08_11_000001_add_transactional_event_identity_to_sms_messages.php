<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->string('event_key')->nullable()->index()->after('template_key');
            $table->uuid('booking_id')->nullable()->index()->after('context_id');
            $table->uuid('booking_item_id')->nullable()->index()->after('booking_id');
            $table->uuid('driver_assignment_id')->nullable()->index()->after('booking_item_id');
            $table->string('idempotency_key')->nullable()->unique()->after('event_key');
            $table->string('source')->default('manual')->index()->after('channel');
            $table->timestamp('triggered_at')->nullable()->index()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['event_key']);
            $table->dropIndex(['booking_id']);
            $table->dropIndex(['booking_item_id']);
            $table->dropIndex(['driver_assignment_id']);
            $table->dropIndex(['source']);
            $table->dropIndex(['triggered_at']);
            $table->dropColumn([
                'event_key',
                'booking_id',
                'booking_item_id',
                'driver_assignment_id',
                'idempotency_key',
                'source',
                'triggered_at',
            ]);
        });
    }
};
