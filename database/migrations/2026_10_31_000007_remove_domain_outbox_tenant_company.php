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
        $this->normalizeTargetCopyReplayChecksums();

        $tableName = 'domain_outbox_events';
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

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

    private function normalizeTargetCopyReplayChecksums(): void
    {
        if (! Schema::hasTable('sales_target_copy_batches') || ! Schema::hasTable('domain_outbox_events')) {
            return;
        }
        $hasCompanyColumn = Schema::hasColumn('domain_outbox_events', 'company_id');

        foreach (DB::table('sales_target_copy_batches')->orderBy('id')->get() as $batch) {
            $rows = is_array($batch->selection_snapshot)
                ? $batch->selection_snapshot
                : json_decode((string) $batch->selection_snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new RuntimeException("Cannot normalize target-copy batch {$batch->id}: selection snapshot is invalid.");
            }
            foreach ($rows as $row) {
                if (! is_array($row) || empty($row['sales_profile_id'])) {
                    throw new RuntimeException("Cannot normalize target-copy batch {$batch->id}: selection snapshot has no profile identity.");
                }
            }

            $facts = [
                'source_period_start' => (string) $batch->source_period_start,
                'source_period_end' => (string) $batch->source_period_end,
                'target_period_start' => (string) $batch->target_period_start,
                'target_period_end' => (string) $batch->target_period_end,
                'rows' => $rows,
            ];
            $globalPreviewChecksum = hash('sha256', CanonicalJson::encode($facts));
            $profileIds = array_values(array_unique(array_map(
                static fn (array $row): string => (string) ($row['sales_profile_id'] ?? ''),
                $rows,
            )));
            sort($profileIds, SORT_STRING);
            $requestFacts = [
                'source_period_start' => (string) $batch->source_period_start,
                'target_period_start' => (string) $batch->target_period_start,
                'profile_ids' => $profileIds,
                'preview_checksum' => $globalPreviewChecksum,
                'reason' => trim((string) $batch->reason),
                'actor_user_id' => (string) $batch->prepared_by,
            ];
            $globalRequestChecksum = hash('sha256', CanonicalJson::encode($requestFacts));

            if (hash_equals((string) $batch->preview_checksum, $globalPreviewChecksum)
                && hash_equals((string) $batch->request_payload_checksum, $globalRequestChecksum)) {
                continue;
            }
            if (! $hasCompanyColumn) {
                throw new RuntimeException("Cannot safely normalize target-copy batch {$batch->id}: its company-free checksums are not current, and legacy company evidence is unavailable.");
            }

            $companyIds = DB::table('domain_outbox_events')->where('domain', 'sales')
                ->where('aggregate_type', 'sales_target_copy_batch')->where('aggregate_id', $batch->id)
                ->whereNotNull('company_id')->distinct()->pluck('company_id');
            if ($companyIds->count() !== 1) {
                throw new RuntimeException("Cannot safely normalize target-copy batch {$batch->id}: expected one legacy outbox company value.");
            }

            $companyId = (string) $companyIds->first();
            $legacyPreviewChecksum = hash('sha256', CanonicalJson::encode(['company_id' => $companyId, ...$facts]));
            $legacyRequestChecksum = hash('sha256', CanonicalJson::encode([
                'company_id' => $companyId,
                ...$requestFacts,
                'preview_checksum' => $legacyPreviewChecksum,
            ]));
            if (! hash_equals((string) $batch->preview_checksum, $legacyPreviewChecksum)
                || ! hash_equals((string) $batch->request_payload_checksum, $legacyRequestChecksum)) {
                throw new RuntimeException("Cannot safely normalize target-copy batch {$batch->id}: its stored checksums do not match its legacy company-scoped evidence.");
            }

            DB::table('sales_target_copy_batches')->where('id', $batch->id)->update([
                'preview_checksum' => $globalPreviewChecksum,
                'request_payload_checksum' => $globalRequestChecksum,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Domain outbox events cannot regain tenant company scope.');
    }
};
