<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_reporting_lines', function (Blueprint $table) {
            $table->string('status', 30)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 160)->nullable()->unique();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['company_id', 'member_staff_id', 'line_type', 'effective_from', 'effective_until'], 'hr_reporting_member_type_effective_index');
        });

        Schema::create('hr_reporting_line_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('reporting_line_id')->constrained('hr_reporting_lines')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->unsignedInteger('reporting_line_version');
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot');
            $table->text('reason');
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 160)->unique();
            $table->char('request_checksum', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['reporting_line_id', 'reporting_line_version'], 'hr_reporting_line_event_version_unique');
            $table->index(['company_id', 'event_type', 'occurred_at'], 'hr_reporting_line_event_company_time_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_reporting_line_events') && DB::table('hr_reporting_line_events')->exists()) {
            throw new LogicException('Refusing to remove retained HR reporting-line history. Disable the feature without deleting hierarchy evidence.');
        }
        Schema::dropIfExists('hr_reporting_line_events');
        Schema::table('hr_reporting_lines', function (Blueprint $table) {
            $table->dropIndex('hr_reporting_member_type_effective_index');
            $table->dropUnique('hr_reporting_lines_idempotency_key_unique');
            $table->dropConstrainedForeignId('updated_user_id');
            $table->dropColumn(['status', 'version', 'reason', 'idempotency_key']);
        });
    }
};
