<?php

use App\Support\Foundation\CanonicalJson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        $this->assertGlobalNotificationPoliciesAreUnique();
        $this->removeCompanyFromPolicyChecksums();

        foreach ([
            'sales_commission_decisions',
            'sales_commission_notification_policy_versions',
            'sales_commission_notification_preferences',
            'sales_commission_notification_deliveries',
        ] as $tableName) {
            $this->dropCompanyColumn($tableName);
        }

        $this->createIndex('sales_commission_notification_policy_versions',
            ['event_type', 'channel', 'recipient_scope', 'version'],
            'sales_commission_notification_global_version_unique', true);
        $this->createIndex('sales_commission_notification_policy_versions',
            ['event_type', 'channel', 'status', 'effective_from'],
            'sales_commission_notification_global_effective_idx');
        $this->createIndex('sales_commission_notification_deliveries',
            ['commission_decision_id', 'created_at'],
            'sales_commission_notification_decision_idx');
    }

    private function assertGlobalNotificationPoliciesAreUnique(): void
    {
        $table = 'sales_commission_notification_policy_versions';
        if (! Schema::hasTable($table)) {
            return;
        }

        $duplicates = DB::table($table)
            ->select(['event_type', 'channel', 'recipient_scope', 'version'])
            ->groupBy('event_type', 'channel', 'recipient_scope', 'version')
            ->havingRaw('COUNT(*) > 1')->limit(20)->get();
        if ($duplicates->isNotEmpty()) {
            $keys = $duplicates->map(fn (object $row): string => implode('/', [
                $row->event_type, $row->channel, $row->recipient_scope, $row->version,
            ]))->all();
            throw new RuntimeException('Cannot make Sales commission notification policies installation-wide; reconcile duplicate policy versions first: '.implode(', ', $keys));
        }
    }

    private function removeCompanyFromPolicyChecksums(): void
    {
        $table = 'sales_commission_notification_policy_versions';
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
            return;
        }

        foreach (DB::table($table)->get() as $policy) {
            $facts = [
                'event_type' => $policy->event_type,
                'channel' => $policy->channel,
                'recipient_scope' => $policy->recipient_scope,
                'version' => (int) $policy->version,
                'title_template' => $policy->title_template,
                'body_template' => $policy->body_template,
                'allowed_placeholders' => json_decode($policy->allowed_placeholders, true, 512, JSON_THROW_ON_ERROR),
                'mandatory' => (bool) $policy->mandatory,
                'escalation_after_minutes' => $policy->escalation_after_minutes,
                'effective_from' => $policy->effective_from,
                'effective_until' => $policy->effective_until,
            ];
            $currentChecksum = hash('sha256', CanonicalJson::encode($facts));
            if (hash_equals((string) $policy->policy_checksum, $currentChecksum)) {
                continue;
            }
            $legacyFacts = ['company_id' => $policy->company_id] + $facts;
            $legacyChecksum = hash('sha256', CanonicalJson::encode($legacyFacts));

            if (hash_equals((string) $policy->policy_checksum, $legacyChecksum)) {
                DB::table($table)->where('id', $policy->id)->update([
                    'policy_checksum' => $currentChecksum,
                ]);
                continue;
            }

            throw new RuntimeException("Cannot remove Sales commission notification company metadata: policy {$policy->id} has an unrecognized checksum.");
        }
    }

    private function dropCompanyColumn(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }
        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['primary'] ?? false) || ($index['type'] ?? null) === 'primary'
                || ! in_array('company_id', $index['columns'], true)) {
                continue;
            }
            TenantIndexDropper::drop($tableName, $index);
        }
        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    private function createIndex(string $tableName, array $columns, string $name, bool $unique = false): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }
        foreach (Schema::getIndexes($tableName) as $index) {
            $isUnique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
            if ($index['columns'] === $columns && (! $unique || $isUnique)) {
                return;
            }
        }

        $baseName = $name;
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
            return DB::table('pg_class as c')->where('c.relname', $name)
                ->whereRaw('c.relnamespace = (SELECT relnamespace FROM pg_class WHERE oid = to_regclass(?))', [$tableName])
                ->exists();
        }

        return Schema::hasIndex($tableName, $name);
    }

    public function down(): void
    {
        throw new RuntimeException('Commission decision and notification records cannot regain tenant company scope.');
    }
};
