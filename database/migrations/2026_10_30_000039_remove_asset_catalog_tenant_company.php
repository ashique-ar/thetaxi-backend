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
        $this->assertUniqueValues('hr_asset_types', 'code');
        $this->assertUniqueValues('hr_asset_items', 'asset_tag');

        foreach (['hr_asset_types', 'hr_asset_items'] as $assetTable) {
            if (! Schema::hasTable($assetTable) || ! Schema::hasColumn($assetTable, 'company_id')) {
                continue;
            }
            foreach (Schema::getForeignKeys($assetTable) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($assetTable, function (Blueprint $table) use ($foreignKey): void {
                        $table->dropForeign($foreignKey['name']);
                    });
                }
            }
            $existingColumnSets = array_map(static fn (array $index): array => $index['columns'], Schema::getIndexes($assetTable));
            foreach (Schema::getIndexes($assetTable) as $index) {
                if (($index['primary'] ?? false) || $index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }
                TenantIndexDropper::drop($assetTable, $index);
                $columns = array_values(array_diff($index['columns'], ['company_id']));
                if (($index['unique'] ?? false) || $index['type'] === 'unique' || $columns === [] || in_array($columns, $existingColumnSets, true)) {
                    continue;
                }
                $this->createIndex($assetTable, $columns, $assetTable.'_installation_lookup_idx');
                $existingColumnSets[] = $columns;
            }
            Schema::table($assetTable, function (Blueprint $table): void {
                $table->dropColumn('company_id');
            });
        }

        $this->createIndex('hr_asset_types', ['code'], 'hr_asset_types_installation_code_unique', true);
        $this->createIndex('hr_asset_items', ['asset_tag'], 'hr_asset_items_installation_tag_unique', true);
    }

    private function assertUniqueValues(string $tableName, string $column): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column)) {
            return;
        }
        $duplicates = DB::table($tableName)->select($column)->groupBy($column)
            ->havingRaw('COUNT(*) > 1')->limit(20)->pluck($column);
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException("Cannot make {$tableName}.{$column} installation-wide; reconcile duplicates first: ".implode(', ', $duplicates->all()));
        }
    }

    private function createIndex(string $tableName, array $columns, string $baseName, bool $unique = false): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }
        foreach (Schema::getIndexes($tableName) as $index) {
            $isUnique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
            if ($index['columns'] === $columns && (! $unique || $isUnique)) {
                return;
            }
        }

        $name = $baseName;
        $suffix = 1;
        while ($this->relationExists($tableName, $name)) {
            $name = $baseName.'_'.$suffix;
            $suffix++;
        }

        if (DB::getDriverName() === 'pgsql') {
            $grammar = DB::connection()->getQueryGrammar();
            $indexType = $unique ? 'UNIQUE ' : '';
            $wrappedColumns = implode(', ', array_map($grammar->wrap(...), $columns));
            DB::statement('CREATE '.$indexType.'INDEX IF NOT EXISTS '.$grammar->wrap($name).' ON '.$grammar->wrapTable($tableName).' ('.$wrappedColumns.')');

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $name, $unique): void {
            $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
        });
    }

    private function relationExists(string $tableName, string $name): bool
    {
        if (DB::getDriverName() === 'pgsql') {
            return DB::table('pg_class as c')
                ->where('c.relname', $name)
                ->whereRaw('c.relnamespace = (SELECT relnamespace FROM pg_class WHERE oid = to_regclass(?))', [$tableName])
                ->exists();
        }

        return Schema::hasIndex($tableName, $name);
    }

    public function down(): void
    {
        throw new RuntimeException('Asset catalogs cannot regain tenant company scope.');
    }
};
