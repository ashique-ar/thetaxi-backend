<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_outbound_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('inquiry_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('sent_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 100)->unique();
            $table->text('recipient_email');
            $table->text('message');
            $table->string('status', 20)->default('sending');
            $table->text('failure_reason')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'inquiry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_outbound_responses');
    }
};
