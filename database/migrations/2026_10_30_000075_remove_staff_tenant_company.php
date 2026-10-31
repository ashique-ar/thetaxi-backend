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
        $tableName = 'staff';
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        $indexes = Schema::getIndexes($tableName);
        foreach ($indexes as $index) {
            $columns = array_values(array_diff($index['columns'], ['company_id']));
            if (! in_array('company_id', $index['columns'], true) || $columns === []
                || ! (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique')) {
                continue;
            }

            $collision = DB::table($tableName)->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->limit(20)->get();
            if ($collision->isNotEmpty()) {
                $details = $collision->map(fn ($row) => json_encode((array) $row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))->implode(', ');
                throw new RuntimeException("Removing Staff company scope would collide on unique columns [".implode(', ', $columns)."]. Reconcile these Staff values before retrying: {$details}");
            }
        }

        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }

        $lookupIndex = 0;
        foreach ($indexes as $index) {
            if (! in_array('company_id', $index['columns'], true)) {
                continue;
            }
            TenantIndexDropper::drop($tableName, $index);
            $columns = array_values(array_diff($index['columns'], ['company_id']));
            if ($columns === []) {
                continue;
            }

            $isUnique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
            $equivalent = collect(Schema::getIndexes($tableName))->contains(fn (array $existing) =>
                $existing['columns'] === $columns && (! $isUnique || ($existing['unique'] ?? false) || ($existing['type'] ?? null) === 'unique'));
            if ($equivalent) {
                continue;
            }

            $lookupIndex++;
            $name = $isUnique ? 'staff_installation_'.$lookupIndex.'_unique' : 'staff_installation_'.$lookupIndex.'_idx';
            while (Schema::hasIndex($tableName, $name)) {
                $lookupIndex++;
                $name = $isUnique ? 'staff_installation_'.$lookupIndex.'_unique' : 'staff_installation_'.$lookupIndex.'_idx';
            }
            Schema::table($tableName, fn (Blueprint $table) => $isUnique
                ? $table->unique($columns, $name)
                : $table->index($columns, $name));
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    public function down(): void
    {
        throw new RuntimeException('Staff cannot regain tenant company assignment.');
    }
};
