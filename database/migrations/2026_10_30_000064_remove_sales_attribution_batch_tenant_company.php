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
        if (! Schema::hasTable('sales_attribution_migration_batches')) {
            return;
        }

        $duplicateKeys = DB::table('sales_attribution_migration_batches')
            ->select('idempotency_key')
            ->groupBy('idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('idempotency_key');
        if ($duplicateKeys->isNotEmpty()) {
            $details = DB::table('sales_attribution_migration_batches')
                ->whereIn('idempotency_key', $duplicateKeys)
                ->orderBy('idempotency_key')->orderBy('id')
                ->get(['id', 'idempotency_key']);
            throw new RuntimeException('Resolve duplicate Sales attribution batch idempotency keys before removing company scope: '.json_encode($details));
        }

        foreach (Schema::getForeignKeys('sales_attribution_migration_batches') as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table('sales_attribution_migration_batches', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }
        foreach (Schema::getIndexes('sales_attribution_migration_batches') as $index) {
            if (in_array('company_id', $index['columns'], true)) {
                TenantIndexDropper::drop('sales_attribution_migration_batches', $index);
            }
        }
        if (Schema::hasColumn('sales_attribution_migration_batches', 'company_id')) {
            Schema::table('sales_attribution_migration_batches', fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        $hasGlobalKey = collect(Schema::getIndexes('sales_attribution_migration_batches'))
            ->contains(fn (array $index) => $index['columns'] === ['idempotency_key'] && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'));
        if (! $hasGlobalKey) {
            Schema::table('sales_attribution_migration_batches', fn (Blueprint $table) => $table->unique('idempotency_key', 'sales_attribution_migration_batch_key'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Sales attribution batches cannot regain tenant company scope.');
    }
};
