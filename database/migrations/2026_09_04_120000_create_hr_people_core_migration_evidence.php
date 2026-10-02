<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_people_import_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('mode', 30); // preview or committed
            $table->string('status', 30);
            $table->string('original_file_name');
            $table->string('disk', 40); $table->string('path');
            $table->char('file_checksum', 64); $table->string('mapping_version', 40);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->json('reconciliation_totals');
            $table->char('request_checksum', 64); $table->string('idempotency_key', 160);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('committed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('committed_at')->nullable(); $table->foreignUuid('created_user_id')->nullable()->constrained('users')->restrictOnDelete(); $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->restrictOnDelete(); $table->timestamps(); $table->softDeletes();
            $table->unique(['company_id', 'idempotency_key']);
        });
        Schema::create('hr_people_import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_job_id')->constrained('hr_people_import_jobs')->restrictOnDelete();
            $table->unsignedInteger('row_number'); $table->string('source_row_key', 160);
            $table->json('normalized_payload'); $table->char('payload_checksum', 64);
            $table->string('outcome', 30); $table->json('errors')->nullable();
            $table->foreignUuid('matched_staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('created_spell_id')->nullable()->constrained('hr_employment_spells')->restrictOnDelete();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->restrictOnDelete(); $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->restrictOnDelete(); $table->timestamps(); $table->softDeletes(); $table->unique(['import_job_id', 'row_number']);
        });
        Schema::create('hr_people_exports', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('disk', 40); $table->string('path'); $table->string('file_name');
            $table->char('file_checksum', 64); $table->unsignedBigInteger('file_size'); $table->unsignedInteger('row_count');
            $table->char('scope_checksum', 64); $table->string('idempotency_key', 160);
            $table->foreignUuid('generated_by')->constrained('users')->restrictOnDelete(); $table->timestamp('generated_at');
            $table->timestamp('expires_at'); $table->unsignedInteger('download_count')->default(0);
            $table->foreignUuid('last_downloaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('last_downloaded_at')->nullable(); $table->foreignUuid('created_user_id')->nullable()->constrained('users')->restrictOnDelete(); $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->restrictOnDelete(); $table->timestamps(); $table->softDeletes();
            $table->unique(['company_id', 'generated_by', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        foreach (['hr_people_import_rows', 'hr_people_import_jobs', 'hr_people_exports'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: export and reconcile retained {$table} evidence first.");
            }
            Schema::dropIfExists($table);
        }
    }
};
