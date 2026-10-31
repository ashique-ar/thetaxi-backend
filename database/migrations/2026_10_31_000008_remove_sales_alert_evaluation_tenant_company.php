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
        $tableName = 'sales_alert_evaluation_runs';
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $duplicates = DB::table($tableName)->select('idempotency_key')
            ->groupBy('idempotency_key')->havingRaw('COUNT(*) > 1')->limit(20)->pluck('idempotency_key');
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Cannot remove Sales alert evaluation company scope; reconcile duplicate installation-wide idempotency keys first: '.implode(', ', $duplicates->all()));
        }

        if (Schema::hasColumn($tableName, 'company_id')) {
            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            foreach (Schema::getIndexes($tableName) as $index) {
                if (($index['primary'] ?? false) || ($index['type'] ?? null) === 'primary'
                    || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }
                TenantIndexDropper::drop($tableName, $index);
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        $indexes = Schema::getIndexes($tableName);
        $hasGlobalIdempotency = collect($indexes)->contains(fn (array $index): bool =>
            ($index['columns'] ?? []) === ['idempotency_key']
            && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'));
        if (! $hasGlobalIdempotency) {
            Schema::table($tableName, fn (Blueprint $table) => $table->unique(
                ['idempotency_key'], 'sales_alert_evaluation_installation_idempotency_unique',
            ));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Sales alert evaluation evidence cannot regain tenant company scope.');
    }
};
