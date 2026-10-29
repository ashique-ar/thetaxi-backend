<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_webhook_events', function (Blueprint $table): void {
            $table->string('message_direction', 16)->nullable()->after('event_type');
            $table->index(['company_id', 'linked_inquiry_id', 'received_at'], 'respond_io_inquiry_message_idx');
        });
    }

    public function down(): void
    {
        Schema::table('integration_webhook_events', function (Blueprint $table): void {
            $table->dropIndex('respond_io_inquiry_message_idx');
            $table->dropColumn('message_direction');
        });
    }
};
