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
        $tableName = 'sales_portfolio_status_policy_versions';
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (Schema::hasColumn($tableName, 'company_id')) {
            foreach (['idempotency_key', 'approval_idempotency_key'] as $keyColumn) {
                $duplicates = DB::table($tableName)->whereNotNull($keyColumn)->select($keyColumn)
                    ->groupBy($keyColumn)->havingRaw('COUNT(*) > 1')->limit(20)->pluck($keyColumn);
                if ($duplicates->isNotEmpty()) {
                    $ids = DB::table($tableName)->whereIn($keyColumn, $duplicates)->orderBy($keyColumn)->orderBy('id')
                        ->get([$keyColumn, 'id'])->map(fn ($row) => "{$row->{$keyColumn}}={$row->id}")->implode(', ');
                    throw new RuntimeException("Sales portfolio policy {$keyColumn} values collide across locations. Reconcile the policy IDs before retrying: {$ids}");
                }
            }

            $overlaps = DB::table($tableName.' as earlier')
                ->join($tableName.' as later', fn ($join) => $join->on('later.id', '>', 'earlier.id'))
                ->where('earlier.status', 'approved')->where('later.status', 'approved')
                ->where(function ($query): void {
                    $query->whereNull('earlier.effective_until')
                        ->orWhereColumn('earlier.effective_until', '>=', 'later.effective_from');
                })
                ->where(function ($query): void {
                    $query->whereNull('later.effective_until')
                        ->orWhereColumn('earlier.effective_from', '<=', 'later.effective_until');
                })
                ->limit(20)->get(['earlier.id as earlier_id', 'later.id as later_id']);
            if ($overlaps->isNotEmpty()) {
                $ids = $overlaps->map(fn ($row) => "{$row->earlier_id}/{$row->later_id}")->implode(', ');
                throw new RuntimeException("Approved Sales portfolio status policies overlap across locations. Reconcile the policy IDs before retrying: {$ids}");
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
        }

        $version = 0;
        foreach (DB::table($tableName)->orderBy('created_at')->orderBy('id')->pluck('id') as $id) {
            DB::table($tableName)->where('id', $id)->update(['version' => ++$version]);
        }

        $this->ensureUniqueIndex($tableName, ['version'], 'sales_portfolio_status_policy_version_global_unique');
        $this->ensureUniqueIndex($tableName, ['idempotency_key'], 'sales_portfolio_status_policy_command_global_unique');
        $this->ensureUniqueIndex($tableName, ['approval_idempotency_key'], 'sales_portfolio_status_policy_approval_global_unique');

        if (Schema::hasColumn($tableName, 'company_id')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    private function ensureUniqueIndex(string $tableName, array $columns, string $name): void
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            if ($index['columns'] === $columns && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique')) {
                return;
            }
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->unique($columns, $name));
    }

    public function down(): void
    {
        throw new RuntimeException('Sales portfolio policies cannot regain tenant company scope.');
    }
};
