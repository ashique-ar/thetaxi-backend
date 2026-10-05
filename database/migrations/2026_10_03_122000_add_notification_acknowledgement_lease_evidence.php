<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_notification_delivery_events') || Schema::hasColumn('hr_notification_delivery_events', 'lease_token_hash')) {
            return;
        }

        Schema::table('hr_notification_delivery_events', function (Blueprint $table): void {
            $table->char('lease_token_hash', 64)->nullable();
            $table->index(['outbox_id', 'lease_token_hash'], 'hr_notification_ack_lease_hash_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('hr_notification_delivery_events', 'lease_token_hash')) {
            return;
        }

        if (DB::table('hr_notification_delivery_events')->whereNotNull('lease_token_hash')->exists()) {
            throw new RuntimeException('Cannot roll back notification acknowledgement lease evidence while delivery events reference it.');
        }

        Schema::table('hr_notification_delivery_events', function (Blueprint $table): void {
            $table->dropIndex('hr_notification_ack_lease_hash_index');
            $table->dropColumn('lease_token_hash');
        });
    }
};
