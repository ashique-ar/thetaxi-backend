<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance_identity_dispositions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained('hr_attendance_devices')->restrictOnDelete();
            $table->string('provider_person_id', 160);
            $table->string('disposition', 40);
            $table->text('reason');
            $table->foreignUuid('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at');
            $table->timestamps();
            $table->unique(['device_id', 'provider_person_id']);
        });
        Schema::create('hr_attendance_credential_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained('hr_attendance_devices')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('provider_person_id', 160);
            $table->string('credential_type', 20);
            $table->string('action', 30);
            $table->string('credential_fingerprint', 64)->nullable();
            $table->string('masked_reference', 40)->nullable();
            $table->string('status', 30);
            $table->text('reason');
            $table->string('idempotency_key', 160)->unique();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->json('provider_result')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'provider_person_id', 'credential_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance_credential_events');
        Schema::dropIfExists('hr_attendance_identity_dispositions');
    }
};
