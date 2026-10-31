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
        if (! Schema::hasTable('sales_profiles')) {
            return;
        }

        $duplicates = DB::table('sales_profiles')->select('sales_code')->groupBy('sales_code')
            ->havingRaw('COUNT(*) > 1')->limit(20)->pluck('sales_code');
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Reconcile duplicate Sales codes before removing Sales Profile company scope: '.implode(', ', $duplicates->all()));
        }

        if (Schema::hasColumn('sales_profiles', 'company_id')) {
            foreach (Schema::getForeignKeys('sales_profiles') as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table('sales_profiles', function (Blueprint $table) use ($foreignKey): void {
                        $table->dropForeign($foreignKey['name']);
                    });
                }
            }
            foreach (Schema::getIndexes('sales_profiles') as $index) {
                if (in_array('company_id', $index['columns'], true)) {
                    TenantIndexDropper::drop('sales_profiles', $index);
                }
            }
            Schema::table('sales_profiles', function (Blueprint $table): void {
                $table->dropColumn('company_id');
            });
        }

        $hasGlobalSalesCode = collect(Schema::getIndexes('sales_profiles'))->contains(
            fn (array $index): bool => ($index['columns'] ?? []) === ['sales_code']
                && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'),
        );
        if (! $hasGlobalSalesCode) {
            Schema::table('sales_profiles', function (Blueprint $table): void {
                $table->unique('sales_code', 'sales_profiles_installation_sales_code_unique');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Sales Profiles cannot regain tenant company scope.');
    }
};
