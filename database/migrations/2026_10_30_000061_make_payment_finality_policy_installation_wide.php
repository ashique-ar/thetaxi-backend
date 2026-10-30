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
        $tableName = 'booking_payment_finality_policies';
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (Schema::hasColumn($tableName, 'company_id')) {
            $overlap = DB::table($tableName.' as older')
                ->join($tableName.' as newer', function ($join) {
                $join->on('older.payment_method', '=', 'newer.payment_method')
                    ->whereColumn('older.id', '<', 'newer.id')
                    ->where('older.status', 'approved')->where('newer.status', 'approved')
                    ->whereColumn('older.effective_from', '<', DB::raw("COALESCE(newer.effective_until, '9999-12-31 23:59:59')"))
                    ->whereColumn('newer.effective_from', '<', DB::raw("COALESCE(older.effective_until, '9999-12-31 23:59:59')"));
                })
                ->when(Schema::hasColumn($tableName, 'deleted_at'), fn ($query) => $query->whereNull('older.deleted_at')->whereNull('newer.deleted_at'))
                ->select(['older.id as older_id', 'newer.id as newer_id', 'older.payment_method'])
                ->first();
            if ($overlap) {
                throw new RuntimeException("Payment finality policies {$overlap->older_id} and {$overlap->newer_id} overlap for {$overlap->payment_method}. Resolve approved policy conflicts before making these policies installation-wide.");
            }
        }

        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }
        foreach (Schema::getIndexes($tableName) as $index) {
            if (in_array('company_id', $index['columns'], true)) {
                TenantIndexDropper::drop($tableName, $index);
            }
        }
        if (Schema::hasColumn($tableName, 'company_id')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        $this->createLookupIndex($tableName);
    }

    private function createLookupIndex(string $tableName): void
    {
        $columns = ['payment_method', 'status', 'effective_from'];
        $indexes = Schema::getIndexes($tableName);
        foreach ($indexes as $index) {
            if ($index['columns'] === $columns) {
                return;
            }
        }

        $name = 'booking_finality_installation_lookup_idx';
        $suffix = 1;
        while (collect($indexes)->contains(fn (array $index) => $index['name'] === $name)) {
            $name = 'booking_finality_installation_lookup_idx_'.$suffix++;
        }

        if (DB::getDriverName() === 'pgsql') {
            $grammar = DB::connection()->getQueryGrammar();
            $wrappedColumns = implode(', ', array_map($grammar->wrap(...), $columns));
            DB::statement('CREATE INDEX IF NOT EXISTS '.$grammar->wrap($name).' ON '.$grammar->wrapTable($tableName).' ('.$wrappedColumns.')');

            return;
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
    }

    public function down(): void
    {
        throw new RuntimeException('Payment finality policies cannot regain tenant company scope.');
    }
};
