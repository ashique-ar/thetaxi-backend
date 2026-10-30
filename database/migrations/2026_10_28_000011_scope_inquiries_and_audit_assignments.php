<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
            $table->index(['company_id', 'assigned_to'], 'inquiries_company_assignee_idx');
        });

        Schema::create('inquiry_assignment_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('inquiry_id')->constrained('inquiries')->restrictOnDelete();
            $table->foreignUuid('from_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('reason', 500);
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->index(['company_id', 'inquiry_id', 'occurred_at'], 'inquiry_assignment_events_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_assignment_events');
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->dropIndex('inquiries_company_assignee_idx');
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
