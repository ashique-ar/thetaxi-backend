<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        foreach (['inquiry_assignment_events', 'inquiry_status_events', 'inquiry_outbound_responses'] as $tableName) {
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
                if (($index['primary'] ?? false) || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }

                TenantIndexDropper::drop($tableName, $index);
                $columns = array_values(array_diff($index['columns'], ['company_id']));
                if ($columns === [] || in_array($columns, $existingColumnSets, true)) {
                    continue;
                }

                Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $index['name']));
                $existingColumnSets[] = $columns;
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Inquiry history and delivery records cannot regain tenant company scope.');
    }
};
