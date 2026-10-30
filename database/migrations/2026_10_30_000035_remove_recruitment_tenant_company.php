<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_job_requisitions') && Schema::hasColumn('hr_job_requisitions', 'request_payload_checksum')) {
            foreach (DB::table('hr_job_requisitions')->get() as $requisition) {
                $requirements = is_array($requisition->requirements)
                    ? $requisition->requirements
                    : json_decode((string) $requisition->requirements, true, 512, JSON_THROW_ON_ERROR);
                $budget = $requisition->budget_snapshot === null
                    ? null
                    : (is_array($requisition->budget_snapshot) ? $requisition->budget_snapshot : json_decode((string) $requisition->budget_snapshot, true, 512, JSON_THROW_ON_ERROR));
                $checksum = hash('sha256', json_encode([
                    'position_id' => $requisition->position_id,
                    'organization_unit_id' => $requisition->organization_unit_id,
                    'code' => trim($requisition->code),
                    'title' => trim($requisition->title),
                    'headcount' => (int) $requisition->headcount,
                    'employment_type_code' => $requisition->employment_type_code,
                    'requirements' => array_values($requirements),
                    'budget_snapshot' => $budget,
                    'target_date' => $requisition->target_date,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                DB::table('hr_job_requisitions')->where('id', $requisition->id)->update(['request_payload_checksum' => $checksum]);
            }
        }

        if (Schema::hasTable('activity_log') && Schema::hasColumn('activity_log', 'properties')) {
            foreach (DB::table('activity_log')->where('log_name', 'hr-recruitment')->get(['id', 'properties']) as $activity) {
                $properties = is_array($activity->properties)
                    ? $activity->properties
                    : json_decode((string) ($activity->properties ?: '{}'), true, 512, JSON_THROW_ON_ERROR);
                unset($properties['company_id'], $properties['attributes']['company_id'], $properties['old']['company_id']);
                DB::table('activity_log')->where('id', $activity->id)->update(['properties' => json_encode($properties, JSON_THROW_ON_ERROR)]);
            }
        }

        foreach (['hr_job_requisitions', 'hr_candidates', 'hr_candidate_applications'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            foreach (Schema::getIndexes($tableName) as $index) {
                if ($index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }
                TenantIndexDropper::drop($tableName, $index);
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        if (Schema::hasColumn('hr_job_requisitions', 'idempotency_key')) {
            $hasLegacyKeyCollisions = DB::table('hr_job_requisitions')->whereNotNull('idempotency_key')
                ->select('idempotency_key')->groupBy('idempotency_key')->havingRaw('COUNT(*) > 1')->exists();
            Schema::table('hr_job_requisitions', function (Blueprint $table) use ($hasLegacyKeyCollisions): void {
                if ($hasLegacyKeyCollisions) {
                    $table->index('idempotency_key', 'hr_job_requisitions_installation_idempotency_idx');
                } else {
                    $table->unique('idempotency_key', 'hr_job_requisitions_installation_idempotency_unique');
                }
            });
        }
        if (Schema::hasTable('hr_candidates')) {
            Schema::table('hr_candidates', fn (Blueprint $table) => $table->index('identity_fingerprint', 'hr_candidates_identity_fingerprint_idx'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Recruitment records cannot regain tenant company scope.');
    }
};
