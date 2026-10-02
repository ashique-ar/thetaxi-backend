<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_custom_field_values', function (Blueprint $table) {
            $table->char('value_checksum', 64)->nullable();
            $table->date('effective_from')->nullable();
            $table->text('change_reason')->nullable();
            $table->index(['owner_type', 'owner_id', 'effective_from'], 'hr_custom_value_owner_effective_index');
        });

        Schema::create('hr_custom_field_value_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('custom_field_value_id')->constrained('hr_custom_field_values')->restrictOnDelete();
            $table->foreignUuid('definition_id')->constrained('hr_custom_field_definitions')->restrictOnDelete();
            $table->unsignedInteger('definition_version');
            $table->string('owner_type', 80);
            $table->uuid('owner_id');
            $table->unsignedInteger('value_version');
            $table->string('event_type', 30);
            $table->text('before_encrypted_value')->nullable();
            $table->char('before_checksum', 64)->nullable();
            $table->text('after_encrypted_value')->nullable();
            $table->char('after_checksum', 64);
            $table->date('effective_from');
            $table->text('reason');
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 160)->unique();
            $table->char('command_checksum', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['custom_field_value_id', 'value_version'], 'hr_custom_value_event_version_unique');
            $table->index(['company_id', 'owner_type', 'owner_id', 'occurred_at'], 'hr_custom_value_event_owner_time_index');
        });

        Schema::create('hr_custom_field_value_access_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('definition_id')->constrained('hr_custom_field_definitions')->restrictOnDelete();
            $table->string('owner_type', 80);
            $table->uuid('owner_id');
            $table->string('access_type', 30);
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['company_id', 'owner_type', 'owner_id', 'occurred_at'], 'hr_custom_value_access_owner_time_index');
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('hr_custom_field_value_events') && DB::table('hr_custom_field_value_events')->exists())
            || (Schema::hasTable('hr_custom_field_value_access_events') && DB::table('hr_custom_field_value_access_events')->exists())) {
            throw new RuntimeException('Custom-field value or access history exists; disable the feature instead of removing retained encrypted Staff evidence.');
        }

        Schema::dropIfExists('hr_custom_field_value_access_events');
        Schema::dropIfExists('hr_custom_field_value_events');
        Schema::table('hr_custom_field_values', function (Blueprint $table) {
            $table->dropIndex('hr_custom_value_owner_effective_index');
            $table->dropColumn(['value_checksum', 'effective_from', 'change_reason']);
        });
    }
};
