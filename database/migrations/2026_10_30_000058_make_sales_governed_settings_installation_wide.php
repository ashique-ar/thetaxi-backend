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
        foreach ([
            'sales_policy_settings' => ['policy_kind', 'status', 'version'],
            'sales_staff_category_definitions' => ['category_name', 'status'],
        ] as $tableName => $lookupColumns) {
            $this->removeCompanyKey($tableName);
            if (Schema::hasTable($tableName)) $this->createLookupIndex($tableName, $lookupColumns);
        }

        $legacyFeatureTable = 'sales_company_feature_settings';
        $featureTable = 'sales_feature_settings';
        if (Schema::hasTable($legacyFeatureTable)) {
            $this->removeCompanyKey($legacyFeatureTable);
            if (! Schema::hasTable($featureTable)) Schema::rename($legacyFeatureTable, $featureTable);
        }
        if (Schema::hasTable($featureTable)) $this->createLookupIndex($featureTable, ['feature_key', 'status', 'version']);
    }

    private function removeCompanyKey(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) return;

        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }
        foreach (Schema::getIndexes($tableName) as $index) {
            if (in_array('company_id', $index['columns'], true)) {
                TenantIndexDropper::drop($tableName, $index);
            }
        }
        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    private function createLookupIndex(string $tableName, array $columns): void
    {
        $name = $tableName.'_installation_lookup_idx';
        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return;
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            $grammar = DB::connection()->getQueryGrammar();
            $wrappedColumns = implode(', ', array_map($grammar->wrap(...), $columns));
            DB::statement('CREATE INDEX IF NOT EXISTS '.$grammar->wrap($name)
                .' ON '.$grammar->wrapTable($tableName).' ('.$wrappedColumns.')');

            return;
        }

        if (! Schema::hasIndex($tableName, $name)) {
            Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Sales governed settings cannot regain company scope.');
    }
};
