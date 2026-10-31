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
        foreach (['sales_target_versions', 'sales_target_copy_batches'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            $existingColumnSets = array_map(
                static fn (array $index): array => $index['columns'],
                Schema::getIndexes($tableName),
            );
            foreach (Schema::getIndexes($tableName) as $index) {
                if (($index['primary'] ?? false) || ($index['type'] ?? null) === 'primary'
                    || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }

                TenantIndexDropper::drop($tableName, $index);
                $columns = array_values(array_diff($index['columns'], ['company_id']));
                $isUnique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
                if ($isUnique || $columns === [] || in_array($columns, $existingColumnSets, true)) {
                    continue;
                }

                $this->createIndex($tableName, $columns);
                $existingColumnSets[] = $columns;
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        $this->makeTransferJobIdempotencyInstallationWide();
    }

    private function makeTransferJobIdempotencyInstallationWide(): void
    {
        $tableName = 'domain_transfer_jobs';
        $columns = ['domain', 'job_type', 'idempotency_key'];
        if (! Schema::hasTable($tableName) || collect($columns)->contains(fn (string $column) => ! Schema::hasColumn($tableName, $column))) {
            return;
        }

        $duplicates = DB::table($tableName)->whereNotNull('idempotency_key')
            ->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->limit(20)->get();
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Resolve duplicate installation-wide transfer job idempotency keys before removing Sales target company scope: '.json_encode($duplicates));
        }

        $hasGlobalKey = collect(Schema::getIndexes($tableName))->contains(
            fn (array $index): bool => ($index['columns'] ?? []) === $columns
                && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'),
        );
        if (! $hasGlobalKey) {
            Schema::table($tableName, fn (Blueprint $table) => $table->unique($columns, 'domain_transfer_job_installation_idempotency_unique'));
        }
    }

    private function createIndex(string $tableName, array $columns): void
    {
        $baseName = $tableName.'_installation_lookup_idx';
        $name = $baseName;
        $suffix = 1;
        while ($this->relationExists($tableName, $name)) {
            $name = $baseName.'_'.$suffix++;
        }

        if (DB::getDriverName() === 'pgsql') {
            $grammar = DB::connection()->getQueryGrammar();
            $wrappedColumns = implode(', ', array_map($grammar->wrap(...), $columns));
            DB::statement('CREATE INDEX IF NOT EXISTS '.$grammar->wrap($name).' ON '.$grammar->wrapTable($tableName).' ('.$wrappedColumns.')');

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $name): void {
            $table->index($columns, $name);
        });
    }

    private function relationExists(string $tableName, string $name): bool
    {
        if (DB::getDriverName() === 'pgsql') {
            return DB::table('pg_class as c')->where('c.relname', $name)
                ->whereRaw('c.relnamespace = (SELECT relnamespace FROM pg_class WHERE oid = to_regclass(?))', [$tableName])
                ->exists();
        }

        return Schema::hasIndex($tableName, $name);
    }

    public function down(): void
    {
        throw new RuntimeException('Sales target ledgers cannot regain tenant company scope.');
    }
};
