<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_status_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('inquiry_id')->constrained('inquiries')->restrictOnDelete();
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->index(['company_id', 'inquiry_id', 'occurred_at'], 'inquiry_status_events_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_status_events');
    }
};
