<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('driver_hire_recovery_events', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->foreignUuid('assignment_id')->constrained('driver_assignments')->restrictOnDelete();
            $table->foreignUuid('driver_id')->constrained('drivers')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('trip_mode', 24);
            $table->string('reason', 64);
            $table->string('outcome', 32);
            $table->string('prior_state', 32)->nullable();
            $table->string('new_state', 32)->nullable();
            $table->string('cause', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('connection_state', 24)->nullable();
            $table->uuid('device_uuid')->nullable();
            $table->string('app_version', 100)->nullable();
            $table->string('app_build', 100)->nullable();
            $table->uuid('correlation_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->index(['assignment_id', 'occurred_at']);
            $table->index(['driver_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_hire_recovery_events');
    }
};
