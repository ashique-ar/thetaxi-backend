<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    private const TABLES = [
        'booking_payment_schedules',
        'booking_payment_schedule_revisions',
        'booking_collection_work_items',
        'booking_collection_submissions',
        'booking_payment_schedule_rules',
        'booking_collection_reminder_deliveries',
    ];

    public function up(): void
    {
        $this->assertGlobalUniqueIndexesCanBeRebuilt();

        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            foreach (Schema::getIndexes($tableName) as $index) {
                if (($index['primary'] ?? false) || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }

                $columns = array_values(array_diff($index['columns'], ['company_id']));
                $unique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
                $this->createReplacementIndex($tableName, $columns, $unique);
                TenantIndexDropper::drop($tableName, $index);
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    private function assertGlobalUniqueIndexesCanBeRebuilt(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getIndexes($tableName) as $index) {
                $unique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
                if (! $unique || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }

                $columns = array_values(array_diff($index['columns'], ['company_id']));
                if ($columns === []) {
                    continue;
                }

                $duplicates = DB::table($tableName)->select($columns)->where(function ($query) use ($columns): void {
                    foreach ($columns as $column) {
                        $query->whereNotNull($column);
                    }
                })->groupBy($columns)->havingRaw('COUNT(*) > 1')->limit(20)->get();
                if ($duplicates->isNotEmpty()) {
                    throw new RuntimeException("Cannot remove {$tableName}.company_id while its unique index {$index['name']} has installation-wide collisions; reconcile duplicate keys first.");
                }
            }
        }
    }

    private function createReplacementIndex(string $tableName, array $columns, bool $unique): void
    {
        if ($columns === []) {
            return;
        }

        foreach (Schema::getIndexes($tableName) as $index) {
            $existingUnique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
            if ($index['columns'] === $columns && (! $unique || $existingUnique)) {
                return;
            }
        }

        $baseName = $tableName.'_installation_'.($unique ? 'unique' : 'lookup').'_idx';
        $name = $baseName;
        $suffix = 1;
        while ($this->relationExists($tableName, $name)) {
            $name = $baseName.'_'.$suffix++;
        }

        if (DB::getDriverName() === 'pgsql') {
            $grammar = DB::connection()->getQueryGrammar();
            $wrappedColumns = implode(', ', array_map($grammar->wrap(...), $columns));
            DB::statement('CREATE '.($unique ? 'UNIQUE ' : '').'INDEX IF NOT EXISTS '.$grammar->wrap($name)
                .' ON '.$grammar->wrapTable($tableName).' ('.$wrappedColumns.')');

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
        throw new RuntimeException('Collection workflow records cannot regain tenant company scope.');
    }
};
