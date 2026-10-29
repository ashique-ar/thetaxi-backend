<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_webhook_events', function (Blueprint $table): void {
            $table->text('outbound_message')->nullable();
            $table->string('outbound_status', 24)->nullable();
            $table->string('outbound_message_id', 120)->nullable();
            $table->timestampTz('outbound_sent_at')->nullable();
            $table->foreignUuid('outbound_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('integration_webhook_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('outbound_by_user_id');
            $table->dropColumn(['outbound_message', 'outbound_status', 'outbound_message_id', 'outbound_sent_at']);
        });
    }
};
