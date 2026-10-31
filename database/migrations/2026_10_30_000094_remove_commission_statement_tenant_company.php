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
        $tableName = 'sales_commission_statements';
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $periodColumns = ['staff_id', 'cycle_version_id', 'period_start', 'period_end', 'version'];
        $duplicates = DB::table($tableName)->select($periodColumns)->groupBy($periodColumns)
            ->havingRaw('COUNT(*) > 1')->limit(20)->get();
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Cannot make commission statement period/version keys installation-wide; reconcile duplicates first: '.json_encode($duplicates->all()));
        }

        if (Schema::hasColumn($tableName, 'company_id')) {
            $this->normalizeLegacyDeliveryChecksums();

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            foreach (Schema::getIndexes($tableName) as $index) {
                if (! in_array('company_id', $index['columns'], true)) {
                    continue;
                }
                TenantIndexDropper::drop($tableName, $index);
                $columns = array_values(array_diff($index['columns'], ['company_id']));
                $unique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
                if ($columns === [] || $unique) {
                    continue;
                }
                $alreadyIndexed = collect(Schema::getIndexes($tableName))->contains(
                    fn (array $existing): bool => $existing['columns'] === $columns,
                );
                if (! $alreadyIndexed) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $tableName.'_installation_lookup_idx'));
                }
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        $hasPeriodUnique = collect(Schema::getIndexes($tableName))->contains(
            fn (array $index): bool => $index['columns'] === $periodColumns
                && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'),
        );
        if (! $hasPeriodUnique) {
            Schema::table($tableName, fn (Blueprint $table) => $table->unique($periodColumns, 'commission_statement_period_installation_unique'));
        }
    }

    private function normalizeLegacyDeliveryChecksums(): void
    {
        if (! Schema::hasTable('sales_commission_accounting_deliveries')
            || ! Schema::hasColumn('sales_commission_accounting_deliveries', 'request_payload_checksum')) {
            return;
        }

        $deliveries = DB::table('sales_commission_accounting_deliveries')->whereNotNull('request_payload_checksum')->get();
        foreach ($deliveries as $delivery) {
            $facts = $delivery->verification_source === 'quickbooks'
                ? [
                    'payout_id' => (string) $delivery->payout_id,
                    'source_event_id' => (string) $delivery->source_outbox_event_id,
                    'provider_transaction_id' => (string) $delivery->provider_transaction_id,
                    'provider_response_checksum' => strtolower((string) $delivery->provider_response_checksum),
                    'idempotency_key' => (string) $delivery->idempotency_key,
                ]
                : [
                    'payout_id' => (string) $delivery->payout_id,
                    'event_type' => (string) $delivery->event_type,
                    'status' => (string) $delivery->status,
                    'message' => (string) $delivery->message,
                    'idempotency_key' => (string) $delivery->idempotency_key,
                ];
            $canonicalChecksum = $this->checksum($facts);
            if (hash_equals((string) $delivery->request_payload_checksum, $canonicalChecksum)) {
                continue;
            }

            $companyIds = DB::table('sales_commission_payout_allocations as allocation')
                ->join('sales_commission_statements as statement', 'statement.id', '=', 'allocation.statement_id')
                ->where('allocation.payout_id', $delivery->payout_id)->distinct()->pluck('statement.company_id');
            if ($companyIds->isEmpty() && Schema::hasTable('domain_outbox_events')) {
                $companyIds = DB::table('domain_outbox_events')->where('domain', 'sales')
                    ->where('aggregate_type', 'commission_payout')->where('aggregate_id', $delivery->payout_id)
                    ->whereNotNull('company_id')->distinct()->pluck('company_id');
            }
            if ($companyIds->count() !== 1) {
                throw new RuntimeException("Cannot normalize legacy commission delivery checksum {$delivery->id}; its payout does not resolve to exactly one former location.");
            }
            $legacyChecksum = $this->checksum(['company_id' => (string) $companyIds->first(), ...$facts]);
            if (! hash_equals((string) $delivery->request_payload_checksum, $legacyChecksum)) {
                throw new RuntimeException("Cannot normalize legacy commission delivery checksum {$delivery->id}; its stored checksum does not match the known request facts.");
            }
            DB::table('sales_commission_accounting_deliveries')->where('id', $delivery->id)
                ->update(['request_payload_checksum' => $canonicalChecksum]);
        }
    }

    private function checksum(array $facts): string
    {
        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function down(): void
    {
        throw new RuntimeException('Commission statements cannot regain tenant company scope.');
    }
};
