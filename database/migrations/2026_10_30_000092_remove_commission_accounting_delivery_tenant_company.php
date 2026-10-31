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
        $tableName = 'sales_commission_accounting_deliveries';
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        foreach (Schema::getIndexes($tableName) as $index) {
            if (! in_array('company_id', $index['columns'], true)) {
                continue;
            }
            $columns = array_values(array_diff($index['columns'], ['company_id']));
            $unique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
            if ($columns !== [] && $unique) {
                $duplicates = DB::table($tableName)->whereNotNull($columns[0])->select($columns)->groupBy($columns)
                    ->havingRaw('COUNT(*) > 1')->limit(20)->get();
                if ($duplicates->isNotEmpty()) {
                    throw new RuntimeException("Cannot remove {$tableName}.company_id while unique index {$index['name']} has installation-wide collisions: ".json_encode($duplicates->all()));
                }
            }
        }

        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }

        foreach (Schema::getIndexes($tableName) as $index) {
            if (! in_array('company_id', $index['columns'], true)) {
                continue;
            }
            $columns = array_values(array_diff($index['columns'], ['company_id']));
            $unique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
            TenantIndexDropper::drop($tableName, $index);
            if ($columns === []) {
                continue;
            }
            $alreadyIndexed = collect(Schema::getIndexes($tableName))->contains(
                fn (array $existing): bool => $existing['columns'] === $columns
                    && ((($existing['unique'] ?? false) || ($existing['type'] ?? null) === 'unique') === $unique),
            );
            if (! $alreadyIndexed) {
                Schema::table($tableName, fn (Blueprint $table) => $unique
                    ? $table->unique($columns, 'commission_delivery_provider_tx_installation_unique')
                    : $table->index($columns, 'commission_delivery_installation_lookup_idx'));
            }
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    public function down(): void
    {
        throw new RuntimeException('Commission accounting deliveries cannot regain tenant company scope.');
    }
};
