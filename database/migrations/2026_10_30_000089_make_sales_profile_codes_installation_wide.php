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
        $tableName = 'sales_profiles';
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'sales_code')) {
            return;
        }

        $duplicates = DB::table($tableName)->select('sales_code')->groupBy('sales_code')
            ->havingRaw('COUNT(*) > 1')->limit(20)->pluck('sales_code');
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Cannot make Sales Profile codes installation-wide; reconcile duplicates first: '.implode(', ', $duplicates->all()));
        }

        foreach (Schema::getIndexes($tableName) as $index) {
            $isUnique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
            if ($isUnique && in_array('sales_code', $index['columns'], true) && $index['columns'] !== ['sales_code']) {
                TenantIndexDropper::drop($tableName, $index);
            }
        }

        if (collect(Schema::getIndexes($tableName))->contains(
            fn (array $index): bool => $index['columns'] === ['sales_code']
                && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'),
        )) {
            return;
        }

        $baseName = 'sales_profiles_installation_code_unique';
        $name = $baseName;
        for ($suffix = 1; $this->relationExists($tableName, $name); $suffix++) {
            $name = $baseName.'_'.$suffix;
        }

        if (DB::getDriverName() === 'pgsql') {
            $grammar = DB::connection()->getQueryGrammar();
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap($name).' ON '.$grammar->wrapTable($tableName).' ('.$grammar->wrap('sales_code').')');

            return;
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->unique('sales_code', $name));
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
        throw new RuntimeException('Sales Profile codes cannot regain location-scoped uniqueness.');
    }
};
