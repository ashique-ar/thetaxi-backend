<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_transfer_jobs', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('failure_summary');
            $table->char('preview_checksum', 64)->nullable()->after('reason');
            $table->char('request_payload_checksum', 64)->nullable()->after('preview_checksum');
            $table->string('idempotency_key', 160)->nullable()->after('request_payload_checksum');
            $table->unique(
                ['domain', 'job_type', 'company_id', 'idempotency_key'],
                'domain_transfer_job_idempotency_unique'
            );
        });

        Schema::table('sales_target_versions', function (Blueprint $table) {
            $table->string('source', 30)->default('manual')->after('sales_profile_id');
            $table->foreignUuid('import_job_id')->nullable()->after('copied_from_target_id')
                ->constrained('domain_transfer_jobs')->restrictOnDelete();
            $table->unsignedBigInteger('import_row_number')->nullable()->after('import_job_id');
            $table->unique(['import_job_id', 'import_row_number'], 'sales_target_import_job_row_unique');
        });
        DB::table('sales_target_versions')->whereNotNull('copy_batch_id')
            ->update(['source' => 'approved_month_copy']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_target_versions', 'import_job_id')
            && (DB::table('sales_target_versions')->whereNotNull('import_job_id')->exists()
                || DB::table('domain_transfer_jobs')->where('domain', 'sales')
                    ->where('job_type', 'sales_target_csv')->exists())) {
            throw new RuntimeException('Rollback refused: export and reconcile immutable Sales target import evidence first.');
        }

        Schema::table('sales_target_versions', function (Blueprint $table) {
            $table->dropUnique('sales_target_import_job_row_unique');
            $table->dropConstrainedForeignId('import_job_id');
            $table->dropColumn(['import_row_number', 'source']);
        });
        Schema::table('domain_transfer_jobs', function (Blueprint $table) {
            $table->dropUnique('domain_transfer_job_idempotency_unique');
            $table->dropColumn(['reason', 'preview_checksum', 'request_payload_checksum', 'idempotency_key']);
        });
    }
};
