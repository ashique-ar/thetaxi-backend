<?php

use App\Support\Foundation\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'sales_period_close_events';
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (Schema::hasColumn($tableName, 'company_id')) {
            $events = DB::table($tableName.' as event')
                ->leftJoin('sales_kpi_snapshots as snapshot', 'snapshot.id', '=', 'event.snapshot_id')
                ->whereNotNull('event.snapshot_id')
                ->get([
                    'event.id', 'event.company_id', 'event.lock_version', 'event.reason', 'event.actor_user_id',
                    'event.preview_checksum', 'event.request_payload_checksum',
                    'snapshot.period_start as snapshot_period_start', 'snapshot.cutoff_at as snapshot_cutoff_at',
                ]);

            foreach ($events as $event) {
                if ($event->snapshot_period_start === null || $event->snapshot_cutoff_at === null
                    || $event->preview_checksum === null) {
                    throw new RuntimeException("Cannot normalize Sales period-close event {$event->id}: its frozen snapshot evidence is unavailable.");
                }
                if ((int) $event->lock_version < 1) {
                    throw new RuntimeException("Cannot normalize Sales period-close event {$event->id}: its lock version is invalid.");
                }
                $facts = [
                    'period_start' => CarbonImmutable::parse($event->snapshot_period_start)->toDateString(),
                    'cutoff_at' => CarbonImmutable::parse($event->snapshot_cutoff_at)->utc()->toIso8601String(),
                    'expected_lock_version' => (int) $event->lock_version - 1,
                    'preview_checksum' => $event->preview_checksum,
                    'reason' => trim((string) $event->reason),
                    'actor_user_id' => (string) $event->actor_user_id,
                ];
                $currentChecksum = hash('sha256', CanonicalJson::encode($facts));
                if (hash_equals((string) $event->request_payload_checksum, $currentChecksum)) {
                    continue;
                }

                $legacyChecksum = hash('sha256', CanonicalJson::encode([
                    'company_id' => $event->company_id,
                    ...$facts,
                ]));
                if (! hash_equals((string) $event->request_payload_checksum, $legacyChecksum)) {
                    throw new RuntimeException("Cannot remove Sales period-close company metadata: event {$event->id} has an unrecognized replay checksum.");
                }

                DB::table($tableName)->where('id', $event->id)->update([
                    'request_payload_checksum' => $currentChecksum,
                ]);
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
    }

    public function down(): void
    {
        throw new RuntimeException('Sales period-close events cannot regain tenant company scope.');
    }
};
