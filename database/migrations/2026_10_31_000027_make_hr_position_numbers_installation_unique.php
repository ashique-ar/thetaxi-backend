<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_positions') || ! Schema::hasColumn('hr_positions', 'position_number')) {
            return;
        }

        $duplicates = DB::table('hr_positions')->select('position_number')->groupBy('position_number')
            ->havingRaw('COUNT(*) > 1')->limit(20)->pluck('position_number');
        if ($duplicates->isNotEmpty()) {
            $details = DB::table('hr_positions')->whereIn('position_number', $duplicates)
                ->orderBy('position_number')->orderBy('id')
                ->get(['id', 'position_number', 'title', 'organization_unit_id', 'effective_from', 'effective_until']);
            throw new RuntimeException('Reconcile duplicate installation-wide HR positions before migration: '.json_encode($details, JSON_THROW_ON_ERROR));
        }

        $hasGlobalUnique = collect(Schema::getIndexes('hr_positions'))->contains(
            fn (array $index): bool => ($index['columns'] ?? []) === ['position_number']
                && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'),
        );
        if (! $hasGlobalUnique) {
            Schema::table('hr_positions', function (Blueprint $table): void {
                $table->unique('position_number', 'hr_positions_installation_number_unique');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('HR position numbers cannot regain company partitioning.');
    }
};
