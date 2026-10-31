<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    private const UNIQUE_KEYS = [
        'hr_job_requisitions' => [['idempotency_key']],
        'hr_lifecycle_templates' => [['code', 'version'], ['idempotency_key']],
        'hr_lifecycle_cases' => [['idempotency_key']],
        'hr_approval_delegations' => [['idempotency_key']],
    ];

    public function up(): void
    {
        foreach (self::UNIQUE_KEYS as $tableName => $keySets) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            foreach ($keySets as $columns) {
                if (array_any($columns, fn (string $column): bool => ! Schema::hasColumn($tableName, $column))) {
                    continue;
                }
                $query = DB::table($tableName);
                if ($columns === ['idempotency_key']) {
                    $query->whereNotNull('idempotency_key');
                }
                $duplicates = $query->select($columns)->groupBy(...$columns)->havingRaw('COUNT(*) > 1')->limit(20)->get();
                if ($duplicates->isNotEmpty()) {
                    throw new RuntimeException("Cannot enforce installation-wide idempotency on {$tableName} (".implode(', ', $columns).'); reconcile duplicate rows first: '.json_encode($duplicates));
                }
            }
        }

        foreach (self::UNIQUE_KEYS as $tableName => $keySets) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            foreach ($keySets as $columns) {
                if (array_any($columns, fn (string $column): bool => ! Schema::hasColumn($tableName, $column))) {
                    continue;
                }
                $this->ensureUniqueIndex($tableName, $columns);
            }
        }
    }

    private function ensureUniqueIndex(string $tableName, array $columns): void
    {
        $indexes = Schema::getIndexes($tableName);
        foreach ($indexes as $index) {
            if (($index['columns'] ?? []) === $columns
                && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique')) {
                return;
            }
        }

        $baseName = $tableName.'_installation_'.implode('_', $columns).'_unique';
        $name = $baseName;
        for ($suffix = 1; $this->relationExists($tableName, $name); $suffix++) {
            $name = $baseName.'_'.$suffix;
        }
        Schema::table($tableName, function (Blueprint $table) use ($columns, $name): void {
            $table->unique($columns, $name);
        });

        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['columns'] ?? []) === $columns
                && ! (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique')) {
                TenantIndexDropper::drop($tableName, $index);
            }
        }
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
        throw new RuntimeException('HR installation-wide idempotency constraints cannot regain company partitioning.');
    }
};
