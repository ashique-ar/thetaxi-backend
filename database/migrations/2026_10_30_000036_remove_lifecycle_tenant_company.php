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
        foreach (DB::table('hr_lifecycle_templates')->get() as $template) {
            $applicability = is_array($template->applicability) ? $template->applicability : json_decode((string) $template->applicability, true, 512, JSON_THROW_ON_ERROR);
            $tasks = is_array($template->task_definitions) ? $template->task_definitions : json_decode((string) $template->task_definitions, true, 512, JSON_THROW_ON_ERROR);
            if ($template->request_payload_checksum !== null) {
                DB::table('hr_lifecycle_templates')->where('id', $template->id)->update(['request_payload_checksum' => hash('sha256', json_encode([
                    'case_type' => $template->case_type, 'code' => trim($template->code), 'version' => (int) $template->version,
                    'applicability' => $applicability, 'task_definitions' => array_values($tasks),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))]);
            }
        }

        foreach (DB::table('hr_lifecycle_cases')->get(['id', 'case_snapshot', 'request_payload_checksum']) as $case) {
            $snapshot = is_array($case->case_snapshot) ? $case->case_snapshot : json_decode((string) $case->case_snapshot, true, 512, JSON_THROW_ON_ERROR);
            unset($snapshot['input']['company_id']);
            $update = ['case_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)];
            if ($case->request_payload_checksum !== null) {
                $input = $snapshot['input'] ?? [];
                $update['request_payload_checksum'] = hash('sha256', json_encode([
                    'staff_id' => $input['staff_id'] ?? null, 'application_id' => $input['application_id'] ?? null,
                    'template_id' => $input['template_id'] ?? null, 'effective_date' => $input['effective_date'] ?? null,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
            DB::table('hr_lifecycle_cases')->where('id', $case->id)->update($update);
        }

        foreach (DB::table('hr_lifecycle_tasks')->whereNotNull('completion_checksum')->get(['id', 'evidence', 'completed_by']) as $task) {
            $evidence = is_array($task->evidence) ? $task->evidence : json_decode((string) $task->evidence, true, 512, JSON_THROW_ON_ERROR);
            DB::table('hr_lifecycle_tasks')->where('id', $task->id)->update(['completion_checksum' => hash('sha256', json_encode([
                'task_id' => $task->id, 'actor_id' => $task->completed_by, 'evidence' => $evidence,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))]);
        }

        if (Schema::hasTable('activity_log') && Schema::hasColumn('activity_log', 'properties')) {
            foreach (DB::table('activity_log')->where('log_name', 'hr-lifecycle')->get(['id', 'properties']) as $activity) {
                $properties = is_array($activity->properties) ? $activity->properties : json_decode((string) ($activity->properties ?: '{}'), true, 512, JSON_THROW_ON_ERROR);
                unset($properties['company_id'], $properties['attributes']['company_id'], $properties['old']['company_id']);
                DB::table('activity_log')->where('id', $activity->id)->update(['properties' => json_encode($properties, JSON_THROW_ON_ERROR)]);
            }
        }

        foreach (['hr_lifecycle_templates', 'hr_lifecycle_cases'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'company_id')) {
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

        $templateNaturalKeyCollision = DB::table('hr_lifecycle_templates')->select(['code', 'version'])->groupBy('code', 'version')->havingRaw('COUNT(*) > 1')->exists();
        Schema::table('hr_lifecycle_templates', function (Blueprint $table) use ($templateNaturalKeyCollision): void {
            if ($templateNaturalKeyCollision) {
                $table->index(['code', 'version'], 'hr_lifecycle_templates_code_version_idx');
            } else {
                $table->unique(['code', 'version'], 'hr_lifecycle_templates_code_version_unique');
            }
        });

        foreach (['hr_lifecycle_templates', 'hr_lifecycle_cases'] as $tableName) {
            $collisions = DB::table($tableName)->whereNotNull('idempotency_key')->select('idempotency_key')->groupBy('idempotency_key')->havingRaw('COUNT(*) > 1')->exists();
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $collisions): void {
                $index = $tableName.'_installation_idempotency_'.($collisions ? 'idx' : 'unique');
                if ($collisions) {
                    $table->index('idempotency_key', $index);
                } else {
                    $table->unique('idempotency_key', $index);
                }
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Lifecycle records cannot regain tenant company scope.');
    }
};
