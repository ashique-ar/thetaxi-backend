<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        foreach (['integration_webhook_events', 'respond_io_agent_mappings', 'integration_contact_messages'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            foreach (Schema::getIndexes($tableName) as $index) {
                if (($index['primary'] ?? false) || ($index['type'] ?? null) === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }

                TenantIndexDropper::drop($tableName, $index);
                $columns = array_values(array_diff($index['columns'], ['company_id']));
                if ($columns === [] || collect(Schema::getIndexes($tableName))->contains(
                    static fn (array $existing): bool => $existing['columns'] === $columns,
                )) {
                    continue;
                }

                $name = $tableName.'_installation_'.substr(sha1(implode('|', $columns)), 0, 12).'_idx';
                Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Respond.io event and message history cannot regain tenant company scope.');
    }
};
