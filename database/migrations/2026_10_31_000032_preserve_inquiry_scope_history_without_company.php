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
        $oldTable = 'inquiry_company_scope_events';
        $historyTable = 'inquiry_scope_history_events';

        if (Schema::hasTable($oldTable) && Schema::hasTable($historyTable)) {
            throw new RuntimeException("Cannot preserve inquiry scope history; both {$oldTable} and {$historyTable} exist.");
        }

        $tableName = Schema::hasTable($oldTable) ? $oldTable : $historyTable;
        if (! Schema::hasTable($tableName)) {
            $legacyDrop = '2026_10_30_000052_drop_inquiry_company_scope_history';
            if (Schema::hasTable('migrations') && DB::table('migrations')->where('migration', $legacyDrop)->exists()) {
                throw new RuntimeException('Inquiry scope history was dropped by migration 2026_10_30_000052 before it could be preserved. Restore inquiry_company_scope_events from backup, then retry the pending history migration.');
            }

            return;
        }

        if (Schema::hasColumn($tableName, 'company_id')) {
            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            $indexes = Schema::getIndexes($tableName);
            foreach ($indexes as $index) {
                if (! in_array('company_id', $index['columns'], true)) {
                    continue;
                }

                TenantIndexDropper::drop($tableName, $index);
                $columns = array_values(array_diff($index['columns'], ['company_id']));
                $existing = array_map(static fn (array $current): array => $current['columns'], Schema::getIndexes($tableName));
                if ($columns !== [] && ! in_array($columns, $existing, true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, 'inquiry_scope_history_inquiry_time_idx'));
                }
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        if ($tableName === $oldTable) {
            Schema::rename($oldTable, $historyTable);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Historical inquiry scope events cannot regain company ownership metadata.');
    }
};
