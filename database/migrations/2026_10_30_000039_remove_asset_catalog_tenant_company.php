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
        foreach (['hr_asset_types', 'hr_asset_items'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }
            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            $existingColumnSets = array_map(static fn (array $index): array => $index['columns'], Schema::getIndexes($tableName));
            foreach (Schema::getIndexes($tableName) as $index) {
                if (($index['primary'] ?? false) || $index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }
                TenantIndexDropper::drop($tableName, $index);
                $columns = array_values(array_diff($index['columns'], ['company_id']));
                if (($index['unique'] ?? false) || $index['type'] === 'unique' || $columns === [] || in_array($columns, $existingColumnSets, true)) {
                    continue;
                }
                $this->createIndex($tableName, $columns, $tableName.'_installation_lookup_idx');
                $existingColumnSets[] = $columns;
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        $typeCodeCollision = DB::table('hr_asset_types')->select('code')->groupBy('code')->havingRaw('COUNT(*) > 1')->exists();
        $this->createIndex('hr_asset_types', ['code'], $typeCodeCollision
            ? 'hr_asset_types_installation_code_idx'
            : 'hr_asset_types_installation_code_unique', ! $typeCodeCollision);

        $assetTagCollision = DB::table('hr_asset_items')->select('asset_tag')->groupBy('asset_tag')->havingRaw('COUNT(*) > 1')->exists();
        $this->createIndex('hr_asset_items', ['asset_tag'], $assetTagCollision
            ? 'hr_asset_items_installation_tag_idx'
            : 'hr_asset_items_installation_tag_unique', ! $assetTagCollision);
    }

    private function createIndex(string $tableName, array $columns, string $baseName, bool $unique = false): void
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            $isUnique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
            if ($index['columns'] === $columns && (! $unique || $isUnique)) {
                return;
            }
        }

        $name = $baseName;
        $suffix = 1;
        while ($this->relationExists($name, $tableName)) {
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

        Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns, $name, $unique): void {
            $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
        });
    }

    private function relationExists(string $name, string $tableName): bool
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
