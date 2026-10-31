<?php

use App\Support\Foundation\CanonicalJson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'domain_transfer_jobs';
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        $this->normalizeTargetImportChecksums();

        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }
        foreach (Schema::getIndexes($tableName) as $index) {
            if (in_array('company_id', $index['columns'], true)) {
                TenantIndexDropper::drop($tableName, $index);
            }
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    private function normalizeTargetImportChecksums(): void
    {
        $requiredJobColumns = [
            'company_id', 'domain', 'job_type', 'file_checksum', 'preview_checksum',
            'request_payload_checksum', 'reason', 'requested_by',
        ];
        if (! Schema::hasTable('sales_target_versions') || ! Schema::hasColumn('sales_target_versions', 'import_job_id')) {
            if (DB::table('domain_transfer_jobs')->where('domain', 'sales')->where('job_type', 'sales_target_csv')->exists()) {
                throw new RuntimeException('Cannot safely remove transfer-job company scope before Sales target import lineage exists.');
            }

            return;
        }
        foreach ($requiredJobColumns as $column) {
            if (! Schema::hasColumn('domain_transfer_jobs', $column)) {
                throw new RuntimeException("Cannot safely normalize Sales target import replay checksums: domain_transfer_jobs.{$column} is missing.");
            }
        }

        $jobs = DB::table('domain_transfer_jobs')->where('domain', 'sales')
            ->where('job_type', 'sales_target_csv')->orderBy('id')->get();
        foreach ($jobs as $job) {
            $periodStarts = DB::table('sales_target_versions')->where('import_job_id', $job->id)
                ->distinct()->pluck('period_start');
            if ($periodStarts->count() !== 1 || $job->preview_checksum === null || $job->request_payload_checksum === null) {
                throw new RuntimeException("Cannot safely remove tenant scope from Sales target import job {$job->id}: replay facts are incomplete or span multiple periods.");
            }

            $facts = [
                'target_period_start' => (string) $periodStarts->first(),
                'file_checksum' => (string) $job->file_checksum,
                'preview_checksum' => (string) $job->preview_checksum,
                'reason' => trim((string) $job->reason),
                'actor_user_id' => (string) $job->requested_by,
            ];
            $installationChecksum = hash('sha256', CanonicalJson::encode($facts));
            if (hash_equals((string) $job->request_payload_checksum, $installationChecksum)) {
                continue;
            }

            $legacyChecksum = hash('sha256', CanonicalJson::encode(['company_id' => $job->company_id, ...$facts]));
            if (! hash_equals((string) $job->request_payload_checksum, $legacyChecksum)) {
                throw new RuntimeException("Cannot safely remove tenant scope from Sales target import job {$job->id}: its stored replay checksum does not match its preserved facts.");
            }

            DB::table('domain_transfer_jobs')->where('id', $job->id)->update([
                'request_payload_checksum' => $installationChecksum,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Transfer jobs cannot regain tenant company scope.');
    }
};
