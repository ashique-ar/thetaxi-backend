<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'website_settings';
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        $duplicates = DB::table($tableName)
            ->when(Schema::hasColumn($tableName, 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->whereNotNull('type')
            ->select('type')->groupBy('type')->havingRaw('COUNT(*) > 1')->limit(20)->pluck('type');

        if ($duplicates->isEmpty()) {
            return;
        }

        $details = DB::table($tableName)
            ->whereIn('type', $duplicates)
            ->when(Schema::hasColumn($tableName, 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->orderBy('type')->get(['id', 'type', 'company_id'])
            ->map(fn (object $row): string => "{$row->type} (id={$row->id}, company_id=".($row->company_id ?? 'null').')')
            ->implode('; ');

        throw new RuntimeException('Reconcile duplicate active website setting types before migration 2026_10_30_000015 removes their company scope: '.$details);
    }

    public function down(): void
    {
    }
};
