<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    private const TABLES = [
        'hr_analytics_definition_versions',
        'hr_analytics_snapshots',
        'hr_workforce_plan_versions',
        'hr_workforce_plan_snapshots',
    ];

    public function up(): void
    {
        $this->refreshPlanChecksums();

        foreach (self::TABLES as $tableName) {
            $this->dropCompanyField($tableName);
        }

        if (Schema::hasTable('hr_analytics_definition_versions') && ! Schema::hasIndex('hr_analytics_definition_versions', 'hr_analytics_definition_code_version_index')) {
            Schema::table('hr_analytics_definition_versions', fn (Blueprint $table) => $table->index(['metric_code', 'version'], 'hr_analytics_definition_code_version_index'));
        }
        if (Schema::hasTable('hr_workforce_plan_versions') && ! Schema::hasIndex('hr_workforce_plan_versions', 'hr_workforce_plan_code_version_index')) {
            Schema::table('hr_workforce_plan_versions', fn (Blueprint $table) => $table->index(['code', 'version'], 'hr_workforce_plan_code_version_index'));
        }
    }

    private function refreshPlanChecksums(): void
    {
        if (! Schema::hasTable('hr_workforce_plan_versions') || ! Schema::hasColumn('hr_workforce_plan_versions', 'company_id')) {
            return;
        }

        DB::table('hr_workforce_plan_versions')->whereNotNull('plan_checksum')->orderBy('id')->chunkById(100, function ($plans): void {
            foreach ($plans as $plan) {
                $planPayload = collect((array) $plan)->except([
                    'company_id', 'status', 'plan_checksum', 'submitted_by', 'submitted_at',
                    'approved_by', 'approved_at', 'decision_reason', 'created_at', 'updated_at',
                ])->all();
                $lines = DB::table('hr_workforce_plan_lines')->where('plan_version_id', $plan->id)->orderBy('id')->get()
                    ->map(fn ($line) => collect((array) $line)->except(['created_at', 'updated_at'])->all())->all();
                $checksum = hash('sha256', json_encode(['plan' => $planPayload, 'lines' => $lines], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                DB::table('hr_workforce_plan_versions')->where('id', $plan->id)->update(['plan_checksum' => $checksum]);
                if (Schema::hasTable('hr_workforce_plan_events')) {
                    DB::table('hr_workforce_plan_events')->where('plan_version_id', $plan->id)
                        ->where('plan_checksum', $plan->plan_checksum)->update(['plan_checksum' => $checksum]);
                }
            }
        });
    }

    private function dropCompanyField(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
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

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'company_id')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->uuid('company_id')->nullable()->index());
            }
        }
    }
};
