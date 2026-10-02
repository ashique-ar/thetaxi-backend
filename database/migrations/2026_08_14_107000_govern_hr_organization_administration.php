<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_organization_units', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
        });
        Schema::table('hr_custom_field_definitions', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('hr_custom_field_values', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('definition_version')->default(1);
        });

        Schema::create('hr_organization_change_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('aggregate_type', 50);
            $table->uuid('aggregate_id');
            $table->string('event_type', 80);
            $table->unsignedInteger('aggregate_version');
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot');
            $table->text('reason');
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 160)->unique();
            $table->char('request_checksum', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['aggregate_type', 'aggregate_id', 'aggregate_version'], 'hr_org_event_aggregate_version_unique');
            $table->index(['company_id', 'aggregate_type', 'occurred_at'], 'hr_org_event_company_type_time_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_organization_change_events') && DB::table('hr_organization_change_events')->exists()) {
            throw new LogicException('Refusing to remove retained HR organization history. Disable the feature without rolling back used governance schema.');
        }
        Schema::dropIfExists('hr_organization_change_events');
        Schema::table('hr_custom_field_values', fn (Blueprint $table) => $table->dropColumn(['version','definition_version']));
        Schema::table('hr_custom_field_definitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_user_id');
            $table->dropColumn('version');
        });
        Schema::table('hr_organization_units', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
