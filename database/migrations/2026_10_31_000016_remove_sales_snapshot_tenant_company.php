<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_kpi_snapshots') || ! Schema::hasColumn('sales_kpi_snapshots', 'company_id')) {
            return;
        }

        foreach (Schema::getForeignKeys('sales_kpi_snapshots') as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table('sales_kpi_snapshots', function (Blueprint $table) use ($foreignKey): void {
                    $table->dropForeign($foreignKey['name']);
                });
            }
        }
        foreach (Schema::getIndexes('sales_kpi_snapshots') as $index) {
            if (in_array('company_id', $index['columns'], true)) {
                TenantIndexDropper::drop('sales_kpi_snapshots', $index);
            }
        }
        Schema::table('sales_kpi_snapshots', function (Blueprint $table): void {
            $table->dropColumn('company_id');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Sales KPI snapshots cannot regain tenant company scope.');
    }
};
