<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('name', 120);
            $table->string('webhook_secret_hash', 64);
            $table->boolean('enabled')->default(true);
            $table->uuid('created_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'provider'], 'integration_connection_company_provider_unique');
        });

        Schema::create('integration_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->constrained('integration_connections')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('event_type', 120)->nullable();
            $table->char('payload_hash', 64);
            $table->longText('payload');
            $table->string('status', 24)->default('received');
            $table->timestampTz('received_at');
            $table->timestamps();
            $table->unique(['connection_id', 'payload_hash'], 'integration_webhook_event_dedupe_unique');
            $table->index(['company_id', 'provider', 'status', 'received_at'], 'integration_webhook_event_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_webhook_events');
        Schema::dropIfExists('integration_connections');
    }
};
