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
        foreach ([
            'sales_commission_hold_releases',
            'sales_commission_hold_resolutions',
            'sales_commission_hold_adjustments',
        ] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            $existingIndexes = Schema::getIndexes($tableName);
            foreach ($existingIndexes as $index) {
                if (! in_array('company_id', $index['columns'], true)) {
                    continue;
                }

                $columns = array_values(array_diff($index['columns'], ['company_id']));
                $unique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
                if ($columns !== [] && $unique) {
                    $duplicates = DB::table($tableName)->select($columns)
                        ->groupBy($columns)->havingRaw('COUNT(*) > 1')->limit(20)->get();
                    if ($duplicates->isNotEmpty()) {
                        throw new RuntimeException("Cannot remove {$tableName}.company_id while unique index {$index['name']} has installation-wide collisions: ".json_encode($duplicates->all()));
                    }
                }

                TenantIndexDropper::drop($tableName, $index);
                if ($columns === []) {
                    continue;
                }

                $alreadyIndexed = collect(Schema::getIndexes($tableName))->contains(
                    fn (array $existing) => $existing['columns'] === $columns
                        && ((($existing['unique'] ?? false) || ($existing['type'] ?? null) === 'unique') === $unique),
                );
                if (! $alreadyIndexed) {
                    $this->createIndex($tableName, $columns, $unique, $tableName.'_installation_'.($unique ? 'unique' : 'lookup').'_idx');
                }
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    private function createIndex(string $tableName, array $columns, bool $unique, string $baseName): void
    {
        $name = $baseName;
        for ($suffix = 1; $this->relationExists($tableName, $name); $suffix++) {
            $name = $baseName.'_'.$suffix;
        }

        if (DB::getDriverName() === 'pgsql') {
            $grammar = DB::connection()->getQueryGrammar();
            $type = $unique ? 'UNIQUE ' : '';
            $wrappedColumns = implode(', ', array_map($grammar->wrap(...), $columns));
            DB::statement('CREATE '.$type.'INDEX IF NOT EXISTS '.$grammar->wrap($name).' ON '.$grammar->wrapTable($tableName).' ('.$wrappedColumns.')');

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $name, $unique): void {
            $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
        });
    }

    private function relationExists(string $tableName, string $name): bool
    {
        if (DB::getDriverName() === 'pgsql') {
            return DB::table('pg_class as c')
                ->where('c.relname', $name)
                ->whereRaw('c.relnamespace = (SELECT relnamespace FROM pg_class WHERE oid = to_regclass(?))', [$tableName])
                ->exists();
        }

        return Schema::hasIndex($tableName, $name);
    }

    public function down(): void
    {
        throw new RuntimeException('Commission hold records cannot regain tenant company scope.');
    }
};
