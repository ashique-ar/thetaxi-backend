<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customer_duplicate_decisions', 'customer_user_aliases'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            $existingColumnSets = array_map(static fn (array $index): array => $index['columns'], Schema::getIndexes($tableName));
            $lookupIndexNumber = 0;
            foreach (Schema::getIndexes($tableName) as $index) {
                if (($index['primary'] ?? false) || ($index['type'] ?? null) === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }
                TenantIndexDropper::drop($tableName, $index);
                $columns = array_values(array_diff($index['columns'], ['company_id']));
                if ($columns === [] || in_array($columns, $existingColumnSets, true)) {
                    continue;
                }

                $indexName = $tableName.'_installation_lookup'.(++$lookupIndexNumber).'_idx';
                while (Schema::hasIndex($tableName, $indexName)) {
                    $indexName = $tableName.'_installation_lookup'.(++$lookupIndexNumber).'_idx';
                }
                Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $indexName));
                $existingColumnSets[] = $columns;
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Customer review history cannot regain tenant company scope.');
    }
};
