<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        $this->createInstallationPeriodLocks();
        $this->assertNoDuplicateCloseEventKeys();
        $this->assertNoDuplicateKeys('sales_performance_alert_action_events', 'idempotency_key');
        $this->assertNoDuplicateKeys('sales_alert_evaluation_runs', 'idempotency_key');

        if (Schema::hasTable('domain_period_locks') && Schema::hasColumn('domain_period_locks', 'company_id')) {
            foreach (Schema::getForeignKeys('domain_period_locks') as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table('domain_period_locks', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            foreach (Schema::getIndexes('domain_period_locks') as $index) {
                if (in_array('company_id', $index['columns'], true)) {
                    TenantIndexDropper::drop('domain_period_locks', $index);
                }
            }
            Schema::table('domain_period_locks', fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
        $this->ensureUniqueIndex('domain_period_locks', ['installation_period_key'], 'domain_period_installation_key_unique');

        if (Schema::hasTable('sales_period_close_events')) {
            foreach (Schema::getIndexes('sales_period_close_events') as $index) {
                if (in_array('company_id', $index['columns'], true)) {
                    TenantIndexDropper::drop('sales_period_close_events', $index);
                }
            }
            if (Schema::hasColumn('sales_period_close_events', 'company_id')) {
                Schema::table('sales_period_close_events', function (Blueprint $table): void {
                    $table->foreignUuid('company_id')->nullable()->change();
                });
            }
            $this->ensureUniqueIndex(
                'sales_period_close_events', ['idempotency_key'], 'sales_period_close_installation_idempotency_unique',
            );
            $this->ensureIndex('sales_period_close_events', ['occurred_at'], 'sales_period_close_installation_time_idx');
        }

        if (Schema::hasTable('sales_kpi_snapshots') && Schema::hasColumn('sales_kpi_snapshots', 'company_id')) {
            Schema::table('sales_kpi_snapshots', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->nullable()->change();
            });
            $this->ensureIndex('sales_kpi_snapshots', ['period_start', 'period_end', 'status'], 'sales_kpi_installation_period_idx');
        }

        foreach ([
            'sales_performance_alerts' => ['status', 'severity', 'detected_at'],
            'sales_performance_alert_action_events' => ['occurred_at'],
            'sales_alert_evaluation_runs' => ['evaluated_at'],
        ] as $tableName => $replacementColumns) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }
            foreach (Schema::getIndexes($tableName) as $index) {
                if (in_array('company_id', $index['columns'], true)) {
                    TenantIndexDropper::drop($tableName, $index);
                }
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->foreignUuid('company_id')->nullable()->change();
            });
            $this->ensureIndex($tableName, $replacementColumns, $tableName.'_installation_lookup_idx');
            if ($tableName === 'sales_performance_alert_action_events') {
                $this->ensureUniqueIndex($tableName, ['idempotency_key'], 'sales_alert_action_installation_idempotency_unique');
            } elseif ($tableName === 'sales_alert_evaluation_runs') {
                $this->ensureUniqueIndex($tableName, ['idempotency_key'], 'sales_alert_evaluation_installation_idempotency_unique');
            }
        }
    }

    private function createInstallationPeriodLocks(): void
    {
        if (! Schema::hasTable('domain_period_locks')) {
            return;
        }

        if (! Schema::hasColumn('domain_period_locks', 'installation_period_key')) {
            Schema::table('domain_period_locks', fn (Blueprint $table) => $table->string('installation_period_key', 64)->nullable());
        }
        $periods = DB::table('domain_period_locks')->whereNull('installation_period_key')
            ->select('domain', 'period_type', 'period_start', 'period_end')
            ->groupBy('domain', 'period_type', 'period_start', 'period_end')->get();
        foreach ($periods as $period) {
            $legacyLocks = DB::table('domain_period_locks')->whereNull('installation_period_key')
                ->where('domain', $period->domain)->where('period_type', $period->period_type)
                ->where('period_start', $period->period_start)->where('period_end', $period->period_end)
                ->orderBy('id')->get();
            if ($legacyLocks->isEmpty()) {
                continue;
            }
            $locked = $legacyLocks->where('state', 'locked')->sortByDesc('locked_at')->first();
            $reopened = $legacyLocks->where('state', 'reopened')->sortByDesc('reopened_at')->first();
            $state = $locked ? 'locked' : ($reopened ? 'reopened' : 'open');
            $latestLockedAt = $legacyLocks->where('state', 'locked')->max('locked_at');
            $latestReopenedAt = $legacyLocks->where('state', 'reopened')->max('reopened_at');
            $key = $this->installationPeriodKey(
                (string) $period->domain, (string) $period->period_type,
                (string) $period->period_start, (string) $period->period_end,
            );
            if (DB::table('domain_period_locks')->where('installation_period_key', $key)->exists()) {
                continue;
            }
            DB::table('domain_period_locks')->insert([
                'id' => (string) Str::uuid(),
                'domain' => $period->domain,
                'period_type' => $period->period_type,
                'period_start' => $period->period_start,
                'period_end' => $period->period_end,
                'installation_period_key' => $key,
                'state' => $state,
                'lock_version' => (int) $legacyLocks->max('lock_version'),
                'reason' => 'Installation-wide period state consolidated from historical company-scoped locks; reopen to rebuild across all Sales Profiles.',
                'locked_by' => $locked?->locked_by,
                'locked_at' => $latestLockedAt,
                'reopened_by' => $reopened?->reopened_by,
                'reopened_at' => $latestReopenedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function installationPeriodKey(string $domain, string $periodType, string $periodStart, string $periodEnd): string
    {
        return hash('sha256', implode('|', [$domain, $periodType, $periodStart, $periodEnd]));
    }

    private function assertNoDuplicateCloseEventKeys(): void
    {
        if (! Schema::hasTable('sales_period_close_events')) {
            return;
        }
        $duplicates = DB::table('sales_period_close_events')->select('idempotency_key')
            ->groupBy('idempotency_key')->havingRaw('COUNT(*) > 1')->limit(20)->pluck('idempotency_key');
        if ($duplicates->isNotEmpty()) {
            $details = DB::table('sales_period_close_events')->whereIn('idempotency_key', $duplicates)
                ->orderBy('idempotency_key')->orderBy('id')->get(['id', 'company_id', 'idempotency_key']);
            throw new RuntimeException('Reconcile duplicate Sales period-close idempotency keys before installation-wide replay: '.json_encode($details));
        }
    }

    private function assertNoDuplicateKeys(string $tableName, string $column): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column)) {
            return;
        }
        $duplicates = DB::table($tableName)->select($column)->groupBy($column)
            ->havingRaw('COUNT(*) > 1')->limit(20)->pluck($column);
        if ($duplicates->isNotEmpty()) {
            $details = DB::table($tableName)->whereIn($column, $duplicates)->orderBy($column)->orderBy('id')
                ->get(['id', $column]);
            throw new RuntimeException("Reconcile duplicate {$tableName}.{$column} values before removing company scope: ".json_encode($details));
        }
    }

    private function ensureUniqueIndex(string $tableName, array $columns, string $name): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }
        $exists = collect(Schema::getIndexes($tableName))->contains(
            fn (array $index): bool => ($index['columns'] ?? []) === $columns
                && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'),
        );
        if (! $exists) {
            Schema::table($tableName, fn (Blueprint $table) => $table->unique($columns, $name));
        }
    }

    private function ensureIndex(string $tableName, array $columns, string $name): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }
        $exists = collect(Schema::getIndexes($tableName))->contains(
            fn (array $index): bool => ($index['columns'] ?? []) === $columns,
        );
        if (! $exists) {
            Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Sales period-close evidence cannot regain company partitioning.');
    }
};
