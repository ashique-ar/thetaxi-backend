<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance_access_groups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('device_id')->constrained('hr_attendance_devices')->restrictOnDelete();
            $t->string('code', 100);
            $t->string('name');
            $t->unsignedInteger('door_no');
            $t->unsignedInteger('plan_template_no');
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('status', 30)->default('active');
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['device_id', 'code']);
        });
        Schema::table('hr_attendance_access_commands', function (Blueprint $t) {
            $t->char('request_checksum', 64)->nullable();
            $t->unsignedInteger('attempt_count')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('reconciled_at')->nullable();
            $t->json('provider_result')->nullable();
        });
        Schema::create('hr_attendance_access_delivery_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('command_id')->constrained('hr_attendance_access_commands')->cascadeOnDelete();
            $t->unsignedInteger('attempt_no');
            $t->string('status', 30);
            $t->text('error_summary')->nullable();
            $t->json('provider_result')->nullable();
            $t->timestamp('attempted_at');
            $t->timestamps();
            $t->unique(['command_id', 'attempt_no']);
        });
        Schema::create('hr_attendance_device_alerts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('device_id')->constrained('hr_attendance_devices')->restrictOnDelete();
            $t->string('alert_type', 60);
            $t->string('severity', 20);
            $t->string('status', 30)->default('open');
            $t->text('message');
            $t->char('dedupe_key', 64)->unique();
            $t->timestamp('detected_at');
            $t->timestamp('resolved_at')->nullable();
            $t->foreignUuid('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('resolution_note')->nullable();
            $t->timestamps();
        });
        Schema::create('hr_attendance_device_config_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('device_id')->constrained('hr_attendance_devices')->restrictOnDelete();
            $t->string('action', 60);
            $t->char('before_checksum', 64)->nullable();
            $t->char('after_checksum', 64)->nullable();
            $t->json('safe_snapshot')->nullable();
            $t->string('status', 30);
            $t->text('reason');
            $t->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('occurred_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance_device_config_events');
        Schema::dropIfExists('hr_attendance_device_alerts');
        Schema::dropIfExists('hr_attendance_access_delivery_attempts');
        Schema::table('hr_attendance_access_commands', function (Blueprint $t) {
            $t->dropColumn(['request_checksum', 'attempt_count', 'next_attempt_at', 'reconciled_at', 'provider_result']);
        });
        Schema::dropIfExists('hr_attendance_access_groups');
    }
};
