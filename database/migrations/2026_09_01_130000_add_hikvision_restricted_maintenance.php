<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance_device_maintenance_commands', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('device_id')->constrained('hr_attendance_devices')->restrictOnDelete();
            $t->string('command_type', 40);
            $t->string('status', 40);
            $t->text('reason');
            $t->string('typed_confirmation', 160);
            $t->string('idempotency_key', 160)->unique();
            $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('requested_at');
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('executed_at')->nullable();
            $t->timestamp('offline_detected_at')->nullable();
            $t->timestamp('recovered_at')->nullable();
            $t->json('provider_result')->nullable();
            $t->text('failure_message')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance_device_maintenance_commands');
    }
};
