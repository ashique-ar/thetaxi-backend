<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    private const LEGACY_TABLE = 'tenant_decision_versions';
    private const TABLE = 'decision_setting_versions';

    public function up(): void
    {
        if (Schema::hasTable(self::LEGACY_TABLE)) {
            if (Schema::hasTable(self::TABLE)) {
                throw new RuntimeException('Both legacy and installation decision history tables exist; reconcile them before retrying.');
            }

            Schema::rename(self::LEGACY_TABLE, self::TABLE);
        }

        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'company_id')) {
            return;
        }

        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }

        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (in_array('company_id', $index['columns'], true)) {
                TenantIndexDropper::drop(self::TABLE, $index);
            }
        }

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    public function down(): void
    {
        throw new RuntimeException('Installation-wide decision history cannot regain tenant company scope.');
    }
};
