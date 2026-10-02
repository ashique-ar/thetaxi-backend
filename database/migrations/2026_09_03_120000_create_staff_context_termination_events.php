<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_context_termination_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('employment_spell_id')->nullable()->constrained('hr_employment_spells')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 160)->unique();
            $table->char('request_checksum', 64);
            $table->text('encrypted_reason');
            $table->json('outcome_snapshot');
            $table->timestampTz('terminated_at');
            $table->timestampsTz();
            $table->index(['company_id', 'terminated_at'], 'staff_termination_company_time_idx');
            $table->index(['staff_id', 'terminated_at'], 'staff_termination_staff_time_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('staff_context_termination_events')
            && DB::table('staff_context_termination_events')->exists()) {
            throw new \RuntimeException(
                'Cannot remove staff_context_termination_events while retained termination evidence exists. Export and approve a retention-safe disposition first.'
            );
        }

        Schema::dropIfExists('staff_context_termination_events');
    }
};
