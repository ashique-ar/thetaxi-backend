<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table): void {
            $table->timestampTz('follow_up_due_at')->nullable();
            $table->text('follow_up_notes')->nullable();
            $table->timestampTz('follow_up_completed_at')->nullable();
        });

        Schema::create('phone_call_follow_up_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('phone_call_id')->constrained('phone_calls')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('outcome', 40);
            $table->text('notes')->nullable();
            $table->timestampTz('attempted_at');
            $table->timestamps();
            $table->index(['phone_call_id', 'attempted_at'], 'phone_call_follow_up_attempts_call_time_idx');
            $table->unique(['company_id', 'idempotency_key'], 'phone_call_follow_up_attempts_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_call_follow_up_attempts');
        Schema::table('phone_calls', fn (Blueprint $table) => $table->dropColumn(['follow_up_due_at', 'follow_up_notes', 'follow_up_completed_at']));
    }
};
