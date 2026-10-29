<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->string('api_key_hash', 64)->nullable()->after('webhook_secret_hash');
        });

        Schema::table('phone_calls', function (Blueprint $table): void {
            $table->string('external_provider', 40)->nullable();
            $table->char('external_call_key', 64)->nullable();
            $table->string('direction', 20)->nullable();
            $table->string('call_status', 24)->nullable();
            $table->string('agent_extension', 40)->nullable();
            $table->string('agent_email', 255)->nullable();
            $table->timestampTz('call_started_at')->nullable();
            $table->timestampTz('call_ended_at')->nullable();
            $table->unsignedInteger('talk_duration_seconds')->nullable();
            $table->foreignUuid('follow_up_task_id')->nullable()->constrained('sales_tasks')->restrictOnDelete();
            $table->unique(['external_provider', 'external_call_key'], 'phone_calls_external_event_unique');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table): void {
            $table->dropUnique('phone_calls_external_event_unique');
            $table->dropConstrainedForeignId('follow_up_task_id');
            $table->dropColumn(['external_provider', 'external_call_key', 'direction', 'call_status', 'agent_extension', 'agent_email', 'call_started_at', 'call_ended_at', 'talk_duration_seconds']);
        });
        Schema::table('integration_connections', fn (Blueprint $table) => $table->dropColumn('api_key_hash'));
    }
};
