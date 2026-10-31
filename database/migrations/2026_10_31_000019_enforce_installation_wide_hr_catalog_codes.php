<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CATALOGS = [
        'hr_organization_units', 'hr_job_families', 'hr_job_grades', 'hr_designations',
        'hr_payroll_groups', 'hr_document_types',
    ];

    public function up(): void
    {
        foreach (self::CATALOGS as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'code')) {
                continue;
            }

            $duplicateCodes = DB::table($tableName)->select('code')->groupBy('code')
                ->havingRaw('COUNT(*) > 1')->limit(20)->pluck('code');
            if ($duplicateCodes->isNotEmpty()) {
                $details = DB::table($tableName)->whereIn('code', $duplicateCodes)
                    ->orderBy('code')->orderBy('id')->get(['id', 'code']);
                throw new RuntimeException("Reconcile duplicate installation-wide codes in {$tableName} before migration: ".json_encode($details));
            }

            $hasGlobalUniqueCode = collect(Schema::getIndexes($tableName))->contains(
                fn (array $index): bool => ($index['columns'] ?? []) === ['code']
                    && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'),
            );
            if (! $hasGlobalUniqueCode) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $table->unique('code', $tableName.'_installation_code_unique');
                });
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('HR catalog codes cannot regain company partitioning.');
    }
};
