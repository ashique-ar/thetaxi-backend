<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_webhook_events', function (Blueprint $table): void {
            $table->string('external_contact_id', 120)->nullable();
            $table->string('external_conversation_id', 120)->nullable();
            $table->string('external_message_id', 120)->nullable();
            $table->unique(['connection_id', 'external_message_id'], 'respond_io_message_id_unique');
            $table->index(['connection_id', 'external_conversation_id'], 'respond_io_conversation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('integration_webhook_events', function (Blueprint $table): void {
            $table->dropUnique('respond_io_message_id_unique');
            $table->dropIndex('respond_io_conversation_idx');
            $table->dropColumn(['external_contact_id', 'external_conversation_id', 'external_message_id']);
        });
    }
};
