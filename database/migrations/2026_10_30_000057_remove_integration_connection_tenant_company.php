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
        if (! Schema::hasTable('integration_connections')) {
            return;
        }

        if (Schema::hasColumn('integration_connections', 'company_id')) {
            foreach (Schema::getForeignKeys('integration_connections') as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table('integration_connections', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            foreach (Schema::getIndexes('integration_connections') as $index) {
                if (in_array('company_id', $index['columns'], true)) {
                    TenantIndexDropper::drop('integration_connections', $index);
                }
            }

            Schema::table('integration_connections', fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        if (! collect(Schema::getIndexes('integration_connections'))->contains(fn (array $index): bool => $index['columns'] === ['provider'])) {
            if (DB::getDriverName() === 'pgsql') {
                $grammar = DB::connection()->getQueryGrammar();
                DB::statement('CREATE INDEX IF NOT EXISTS '.$grammar->wrap('integration_connections_provider_idx')
                    .' ON '.$grammar->wrapTable('integration_connections').' ('.$grammar->wrap('provider').')');
            } else {
                Schema::table('integration_connections', fn (Blueprint $table) => $table->index('provider', 'integration_connections_provider_idx'));
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Integration connections cannot regain tenant company scope.');
    }
};
