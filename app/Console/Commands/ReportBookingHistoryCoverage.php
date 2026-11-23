<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
        $this->line('Failed capture attempts are not retained; model-observed mutations use atomic wrappers, while unverified writers require separate coverage checks.');

        return self::SUCCESS;
    }
}
