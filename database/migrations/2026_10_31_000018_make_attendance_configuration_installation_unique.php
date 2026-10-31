<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hr_work_calendars', 'hr_shift_definitions', 'hr_attendance_policies'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $duplicates = DB::table($tableName)->select('code', 'effective_from')
                ->groupBy('code', 'effective_from')->havingRaw('COUNT(*) > 1')->get();
            foreach ($duplicates as $duplicate) {
                $rows = DB::table($tableName)->where('code', $duplicate->code)
                    ->whereDate('effective_from', $duplicate->effective_from)->orderBy('id')->get(['id']);

                // Keep ID-based roster and policy references intact while making each former company copy addressable.
                foreach ($rows->skip(1) as $row) {
                    $prefix = 'legacy_'.substr(str_replace('-', '', (string) $row->id), 0, 20);
                    $code = $prefix;
                    $suffix = 1;
                    while (DB::table($tableName)->where('code', $code)
                        ->whereDate('effective_from', $duplicate->effective_from)->exists()) {
                        $code = $prefix.'_'.$suffix++;
                    }

                    DB::table($tableName)->where('id', $row->id)->update([
                        'code' => $code,
                        'updated_at' => now(),
                    ]);
                }
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
