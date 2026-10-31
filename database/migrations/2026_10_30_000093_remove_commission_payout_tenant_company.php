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
        $tableName = 'sales_commission_payouts';
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $duplicates = DB::table($tableName)->select('payment_reference')->groupBy('payment_reference')
            ->havingRaw('COUNT(*) > 1')->limit(20)->pluck('payment_reference');
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Cannot make commission payout references installation-wide; reconcile duplicates first: '.implode(', ', $duplicates->all()));
        }

        if (Schema::hasColumn($tableName, 'company_id')) {
            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            foreach (Schema::getIndexes($tableName) as $index) {
                if (! in_array('company_id', $index['columns'], true)) {
                    continue;
                }
                TenantIndexDropper::drop($tableName, $index);
                $columns = array_values(array_diff($index['columns'], ['company_id']));
                $unique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
                if ($columns === [] || $unique || $columns === ['payment_reference']) {
                    continue;
                }
                $alreadyIndexed = collect(Schema::getIndexes($tableName))->contains(
                    fn (array $existing): bool => $existing['columns'] === $columns,
                );
                if (! $alreadyIndexed) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $tableName.'_installation_lookup_idx'));
                }
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        $hasReferenceUnique = collect(Schema::getIndexes($tableName))->contains(
            fn (array $index): bool => $index['columns'] === ['payment_reference']
                && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'),
        );
        if (! $hasReferenceUnique) {
            Schema::table($tableName, fn (Blueprint $table) => $table->unique('payment_reference', 'commission_payout_reference_installation_unique'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Commission payouts cannot regain tenant company scope.');
    }
};
