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
                if ($index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }
                TenantIndexDropper::drop($tableName, $index);
                $columns = array_values(array_diff($index['columns'], ['company_id']));
                if ($index['type'] === 'unique' || $columns === [] || in_array($columns, $existingColumnSets, true)) {
                    continue;
                }
                Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $tableName.'_installation_lookup_idx'));
                $existingColumnSets[] = $columns;
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        $typeCodeCollision = DB::table('hr_asset_types')->select('code')->groupBy('code')->havingRaw('COUNT(*) > 1')->exists();
        Schema::table('hr_asset_types', function (Blueprint $table) use ($typeCodeCollision): void {
            $typeCodeCollision
                ? $table->index('code', 'hr_asset_types_installation_code_idx')
                : $table->unique('code', 'hr_asset_types_installation_code_unique');
        });

        $assetTagCollision = DB::table('hr_asset_items')->select('asset_tag')->groupBy('asset_tag')->havingRaw('COUNT(*) > 1')->exists();
        Schema::table('hr_asset_items', function (Blueprint $table) use ($assetTagCollision): void {
            $assetTagCollision
                ? $table->index('asset_tag', 'hr_asset_items_installation_tag_idx')
                : $table->unique('asset_tag', 'hr_asset_items_installation_tag_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Asset catalogs cannot regain tenant company scope.');
    }
};
