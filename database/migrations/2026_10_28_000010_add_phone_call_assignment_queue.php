<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('assigned_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['company_id', 'assigned_user_id', 'created_at'], 'phone_calls_assignment_queue_idx');
        });

        $defaultIds = DB::table('companies')->where('is_active', true)->where('is_default', true)->limit(2)->pluck('id');
        if ($defaultIds->isEmpty()) {
            $defaultIds = DB::table('companies')->where('is_active', true)->orderBy('created_at')->orderBy('id')->limit(2)->pluck('id');
        }
        if ($defaultIds->count() === 1) {
            DB::table('phone_calls')->whereNull('company_id')->update(['company_id' => $defaultIds->first()]);
        }

        Schema::create('phone_call_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('phone_call_id')->constrained('phone_calls')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('from_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('event_at');
            $table->timestamps();
            $table->index(['company_id', 'phone_call_id', 'event_at'], 'phone_call_assignments_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_call_assignments');
        Schema::table('phone_calls', function (Blueprint $table): void {
            $table->dropIndex('phone_calls_assignment_queue_idx');
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
