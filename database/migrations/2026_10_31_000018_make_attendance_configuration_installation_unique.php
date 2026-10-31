<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hr_shift_definitions', 'hr_attendance_policies'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $duplicates = DB::table($tableName)->select('code', 'effective_from')
                ->groupBy('code', 'effective_from')->havingRaw('COUNT(*) > 1')->limit(20)->get();
            if ($duplicates->isNotEmpty()) {
                $details = collect($duplicates)->map(fn ($row) => DB::table($tableName)
                    ->where('code', $row->code)->where('effective_from', $row->effective_from)
                    ->orderBy('id')->get(['id', 'code', 'effective_from']))->flatten(1)->all();
                throw new RuntimeException("Reconcile duplicate installation-wide {$tableName} code/effective-date versions before migration: ".json_encode($details));
            }

            $hasUniqueVersionKey = collect(Schema::getIndexes($tableName))->contains(
                fn (array $index): bool => ($index['columns'] ?? []) === ['code', 'effective_from']
                    && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'),
            );
            if (! $hasUniqueVersionKey) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $table->unique(['code', 'effective_from'], $tableName.'_installation_code_effective_unique');
                });
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Attendance configuration uniqueness cannot regain company partitioning.');
    }
};
