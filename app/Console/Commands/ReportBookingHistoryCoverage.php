<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportBookingHistoryCoverage extends Command
{
    protected $signature = 'booking-history:coverage';

    protected $description = 'Report recorded booking-history coverage by source and event type.';

    public function handle(): int
    {
        $totals = DB::table('booking_history_events')
            ->selectRaw('COUNT(*) AS total_events')
            ->selectRaw("SUM(CASE WHEN provenance = 'live' THEN 1 ELSE 0 END) AS live_events")
            ->selectRaw("SUM(CASE WHEN provenance = 'reconstructed' THEN 1 ELSE 0 END) AS reconstructed_events")
            ->selectRaw('SUM(CASE WHEN actor_user_id IS NULL THEN 1 ELSE 0 END) AS events_without_user_actor')
            ->selectRaw('SUM(CASE WHEN idempotency_key IS NULL THEN 1 ELSE 0 END) AS events_without_idempotency_key')
            ->first();

        $groups = DB::table('booking_history_events')
            ->select(['event_type', 'source', 'provenance'])
            ->selectRaw('COUNT(*) AS event_count')
            ->groupBy('event_type', 'source', 'provenance')
            ->orderBy('source')->orderBy('event_type')->orderBy('provenance')
            ->get()
            ->map(fn (object $row): array => [
                $row->event_type,
                $row->source ?: '(unknown)',
                $row->provenance,
                (int) $row->event_count,
            ]);

        $duplicateIdempotencyKeys = DB::table('booking_history_events')
            ->whereNotNull('idempotency_key')
            ->select('idempotency_key')
            ->groupBy('idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $sourceParity = collect([
            $this->sourceParity('booking_statuses', 'booking-status:', 'booking_statuses', 'booking_status_changed'),
            $this->sourceParity('financial_audit_events', 'financial-audit:', 'financial_audit_events'),
            $this->sourceParity('booking_activities', 'legacy:booking-price:', 'legacy:booking_activities', 'booking_price_changed', 'trip_price_changed'),
        ])->filter()->values();

        $this->table(['Measure', 'Count'], [
            ['Coverage start', config('booking_history.coverage_start') ?: 'unknown'],
            ['Total events', (int) ($totals->total_events ?? 0)],
            ['Live events', (int) ($totals->live_events ?? 0)],
            ['Reconstructed events', (int) ($totals->reconstructed_events ?? 0)],
            ['Events without user actor', (int) ($totals->events_without_user_actor ?? 0)],
            ['Events without idempotency key', (int) ($totals->events_without_idempotency_key ?? 0)],
            ['Duplicate idempotency keys', $duplicateIdempotencyKeys],
        ]);
        $this->table(['Event type', 'Source', 'Provenance', 'Count'], $groups->all());
        $this->table(['Verifiable source rows currently present', 'Rows', 'Matched ledger rows', 'Unmatched rows'], $sourceParity->all());
        $this->line('Source-row parity covers only the listed retained records; it does not prove complete historical capture. Failed capture attempts are not retained.');

        return self::SUCCESS;
    }

    private function sourceParity(
        string $table,
        string $keyPrefix,
        string $ledgerSource,
        ?string $ledgerEventType = null,
        ?string $sourceEventKey = null,
    ): ?array {
        if (!Schema::hasTable($table)) return null;

        $source = DB::table($table . ' as source')->whereNotNull('source.booking_id');
        if ($sourceEventKey !== null) $source->where('source.event_key', $sourceEventKey);
        $sourceRows = (clone $source)->count();
        $matched = (clone $source)->whereExists(function ($query) use ($keyPrefix, $ledgerSource, $ledgerEventType): void {
            $query->selectRaw('1')->from('booking_history_events as history')
                ->where('history.source', $ledgerSource)
                ->when($ledgerEventType, fn ($history) => $history->where('history.event_type', $ledgerEventType))
                ->whereRaw($this->sourceIdempotencyMatchSql($keyPrefix));
        })->count();

        return [$table, $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function sourceIdempotencyMatchSql(string $keyPrefix): string
    {
        $driver = DB::connection()->getDriverName();
        $id = match ($driver) {
            'pgsql' => "'" . $keyPrefix . "' || CAST(source.id AS TEXT)",
            'mysql' => "CONCAT('" . $keyPrefix . "', CAST(source.id AS CHAR))",
            default => "'" . $keyPrefix . "' || CAST(source.id AS TEXT)",
        };

        return 'history.idempotency_key = ' . $id;
    }
}
