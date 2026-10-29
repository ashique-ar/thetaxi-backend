<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_contact_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->constrained('integration_connections')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('external_contact_id', 120);
            $table->string('external_message_id', 120);
            $table->string('traffic', 16)->default('unknown');
            $table->string('sender_source', 80)->nullable();
            $table->string('message_type', 80)->nullable();
            $table->longText('content')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('synced_at');
            $table->foreignUuid('linked_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('linked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('linked_at')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'external_message_id'], 'integration_contact_message_external_unique');
            $table->index(['company_id', 'linked_customer_id', 'sent_at'], 'integration_contact_message_customer_idx');
            $table->index(['connection_id', 'external_contact_id', 'sent_at'], 'integration_contact_message_contact_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_contact_messages');
    }
};
