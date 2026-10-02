<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_acting_appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('employment_spell_id')->constrained('hr_employment_spells')->restrictOnDelete();
            $table->foreignUuid('source_assignment_id')->constrained('hr_employment_assignments')->restrictOnDelete();
            $table->foreignUuid('acting_position_id')->constrained('hr_positions')->restrictOnDelete();
            $table->foreignUuid('acting_manager_staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_until');
            $table->string('status', 30)->default('pending_approval');
            $table->unsignedInteger('version')->default(1);
            $table->text('reason');
            $table->json('source_assignment_snapshot');
            $table->json('acting_assignment_snapshot');
            $table->json('restoration_assignment_snapshot');
            $table->json('excluded_impact_snapshot');
            $table->char('request_checksum', 64);
            $table->string('idempotency_key', 160)->unique();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('acting_assignment_id')->nullable()->constrained('hr_employment_assignments')->restrictOnDelete();
            $table->foreignUuid('restoration_assignment_id')->nullable()->constrained('hr_employment_assignments')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status', 'effective_from']);
            $table->index(['staff_id', 'effective_from', 'effective_until']);
        });

        Schema::create('hr_acting_appointment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('acting_appointment_id')->constrained('hr_acting_appointments')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->unsignedInteger('version');
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot');
            $table->text('reason');
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 160)->unique();
            $table->char('command_checksum', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['acting_appointment_id', 'version']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_acting_appointments') && DB::table('hr_acting_appointments')->exists()) {
            throw new RuntimeException('Acting-appointment history exists; disable the feature instead of removing retained workforce evidence.');
        }

        Schema::dropIfExists('hr_acting_appointment_events');
        Schema::dropIfExists('hr_acting_appointments');
    }
};
