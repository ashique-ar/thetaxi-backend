<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'sales_collection_company_repairs' => ['company_id', 'before_company_id', 'after_company_id'],
            'sales_collection_company_repair_rollbacks' => ['company_id'],
        ] as $tableName => $companyColumns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $presentColumns = array_values(array_filter($companyColumns, fn (string $column): bool => Schema::hasColumn($tableName, $column)));
            if ($presentColumns === []) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (array_intersect($presentColumns, $foreignKey['columns']) !== []) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            foreach (Schema::getIndexes($tableName) as $index) {
                if (array_intersect($presentColumns, $index['columns']) !== []) {
                    TenantIndexDropper::drop($tableName, $index);
                }
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($presentColumns));
        }

        foreach ([
            'sales_collection_company_repairs' => 'sales_collection_repairs',
            'sales_collection_company_repair_rollbacks' => 'sales_collection_repair_rollbacks',
            'sales_collection_company_repair_rollback_items' => 'sales_collection_repair_rollback_items',
        ] as $oldName => $newName) {
            if (Schema::hasTable($oldName) && Schema::hasTable($newName)) {
                throw new RuntimeException("Cannot archive {$oldName}; both legacy and installation-wide tables exist.");
            }
            if (Schema::hasTable($oldName)) {
                Schema::rename($oldName, $newName);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Archived collection repair records cannot regain tenant company metadata.');
    }
};
