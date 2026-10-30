<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    private const TABLES = [
        'hr_review_cycles',
        'hr_review_templates',
        'hr_performance_reviews',
        'hr_goals',
        'hr_achievements',
        'hr_development_plans',
        'hr_performance_appeals',
        'hr_continuous_feedback',
        'hr_critical_role_plans',
        'hr_talent_recommendations',
        'hr_courses',
        'hr_learning_requirements',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            foreach (Schema::getIndexes($tableName) as $index) {
                if (($index['primary'] ?? false) || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }

                TenantIndexDropper::drop($tableName, $index);
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        $this->ensureGlobalUnique('hr_review_cycles', ['code'], 'hr_review_cycles_code');
        $this->ensureGlobalUnique('hr_review_templates', ['code', 'version'], 'hr_review_templates_code_version');
        $this->ensureGlobalUnique('hr_courses', ['code'], 'hr_courses_code');
        $this->ensureGlobalUnique('hr_critical_role_plans', ['position_id'], 'hr_critical_role_plans_position');
    }

    private function ensureGlobalUnique(string $tableName, array $columns, string $name): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $duplicates = DB::table($tableName)->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists();
        foreach (Schema::getIndexes($tableName) as $index) {
            $isUnique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
            if ($index['columns'] === $columns && ($duplicates || $isUnique)) {
                return;
            }
        }

        $indexName = $duplicates ? $name.'_lookup' : $name.'_unique';
        if (DB::getDriverName() === 'pgsql') {
            $grammar = DB::connection()->getQueryGrammar();
            $type = $duplicates ? '' : 'UNIQUE ';
            $wrappedColumns = implode(', ', array_map($grammar->wrap(...), $columns));
            DB::statement('CREATE '.$type.'INDEX IF NOT EXISTS '.$grammar->wrap($indexName).' ON '.$grammar->wrapTable($tableName).' ('.$wrappedColumns.')');

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName, $duplicates): void {
            $duplicates ? $table->index($columns, $indexName) : $table->unique($columns, $indexName);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('HR talent records cannot regain tenant company scope.');
    }
};
