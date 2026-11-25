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
        if (!Schema::hasTable('booking_history_events')) {
            $this->error('Booking history ledger is not installed. Run the reviewed migrations before requesting coverage.');
            return self::FAILURE;
        }

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
            $this->sourceParity('booking_statuses', [['prefix' => 'booking-status:', 'source' => 'booking_statuses', 'event_type' => 'booking_status_changed']]),
            $this->approvalDecisionParity(),
            $this->paymentTransactionParity(),
            $this->sourceParity('financial_audit_events', [['prefix' => 'financial-audit:', 'source' => 'financial_audit_events']]),
            $this->sourceParity('booking_activities', [
                ['prefix' => 'booking-activity:', 'source' => 'booking_activities', 'event_type' => 'trip_price_changed'],
                ['prefix' => 'legacy:booking-price:', 'source' => 'legacy:booking_activities', 'event_type' => 'booking_price_changed'],
            ], 'trip_price_changed'),
            $this->bookingInquiryParity(),
            $this->postTripMaintenanceParity(),
            $this->salesAttributionParity(),
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
        array $ledgerIdentities,
        ?string $sourceEventKey = null,
        ?string $dateColumn = null,
        ?string $since = null,
    ): ?array {
        if (!Schema::hasTable($table)) return null;

        $source = DB::table($table . ' as source')->whereNotNull('source.booking_id');
        if ($sourceEventKey !== null) $source->where('source.event_key', $sourceEventKey);
        if ($dateColumn !== null && $since !== null) $source->where('source.' . $dateColumn, '>=', $since);
        $sourceRows = (clone $source)->count();
        $matched = (clone $source)->whereExists(function ($query) use ($ledgerIdentities): void {
            $query->selectRaw('1')->from('booking_history_events as history')
                ->where(function ($matches) use ($ledgerIdentities): void {
                    foreach ($ledgerIdentities as $identity) {
                        $matches->orWhere(function ($match) use ($identity): void {
                            $match->where('history.source', $identity['source'])
                                ->when($identity['event_type'] ?? null, fn ($match, $eventType) => $match->where('history.event_type', $eventType))
                                ->whereRaw($this->sourceIdempotencyMatchSql($identity['prefix']));
                        });
                    }
                });
        })->count();

        return [$table, $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function sourceIdempotencyMatchSql(string $keyPrefix): string
    {
        $driver = DB::connection()->getDriverName();
        $id = match ($driver) {
            'pgsql' => "'" . $keyPrefix . "' || CAST(source.id AS TEXT)",
            'mysql', 'mariadb' => "CONCAT('" . $keyPrefix . "', CAST(source.id AS CHAR))",
            'sqlsrv' => "CONCAT('" . $keyPrefix . "', CONVERT(varchar(100), source.id))",
            default => "'" . $keyPrefix . "' || CAST(source.id AS TEXT)",
        };

        return 'history.idempotency_key = ' . $id;
    }

    private function salesAttributionParity(): ?array
    {
        if (!Schema::hasTable('sales_booking_attribution_events') || !Schema::hasTable('sales_booking_attributions')) return null;

        $source = DB::table('sales_booking_attribution_events as source')
            ->join('sales_booking_attributions as attribution', 'attribution.id', '=', 'source.attribution_id')
            ->whereNotNull('attribution.booking_id');
        $sourceRows = (clone $source)->count();
        $matched = (clone $source)->whereExists(function ($query): void {
            $query->selectRaw('1')->from('booking_history_events as history')
                ->where('history.source', 'sales_booking_attribution_events')
                ->whereRaw($this->sourceIdempotencyMatchSql('sales-attribution-event:'));
        })->count();

        return ['sales_booking_attribution_events', $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function approvalDecisionParity(): ?array
    {
        if (!Schema::hasTable('booking_approvals')) return null;

        $sourceRows = 0;
        $matched = 0;
        DB::table('booking_approvals')->whereNotNull('booking_id')->orderBy('id')->chunkById(200, function ($approvals) use (&$sourceRows, &$matched): void {
            $keys = [];
            foreach ($approvals as $approval) {
                $conditions = is_array($approval->conditions)
                    ? $approval->conditions
                    : (json_decode((string) ($approval->conditions ?? ''), true) ?: []);
                $decisions = is_array($conditions['step_decisions'] ?? null) ? $conditions['step_decisions'] : [];
                foreach ($decisions as $decision) {
                    if (empty($decision['user_id']) || empty($decision['decided_at'])) continue;
                    $step = max(1, (int) ($decision['step'] ?? 1));
                    $keys[] = 'booking-approval-decision:' . $approval->id . ':' . $step . ':' . $decision['user_id'];
                }

                $status = (string) ($approval->status ?? '');
                $decidedAt = $status === 'rejected' ? $approval->rejected_at : ($status === 'approved' ? $approval->approved_at : null);
                $approverId = $approval->approver_id;
                if (!in_array($status, ['approved', 'rejected'], true) || !$decidedAt || !$approverId) continue;
                if ($status === 'approved' && collect($decisions)->contains(fn ($decision) => (string) ($decision['user_id'] ?? '') === (string) $approverId)) continue;
                $step = max(1, count($decisions) + 1);
                $keys[] = 'booking-approval-decision:' . $approval->id . ':' . $step . ':' . $approverId;
            }

            $keys = array_values(array_unique($keys));
            $sourceRows += count($keys);
            if ($keys) {
                $matched += DB::table('booking_history_events')->whereIn('idempotency_key', $keys)->count();
            }
        });

        return ['verifiable booking approval decisions', $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function paymentTransactionParity(): ?array
    {
        $coverageStart = config('booking_history.coverage_start');
        if (!Schema::hasTable('payment_transactions') || !$coverageStart) return null;

        $sourceRows = 0;
        $matched = 0;
        DB::table('payment_transactions')->whereNotNull('booking_id')->where('created_at', '>=', $coverageStart)
            ->orderBy('id')->chunkById(200, function ($rows) use (&$sourceRows, &$matched): void {
                foreach ($rows as $transaction) {
                    $keys = [
                        'payment-transaction-status:' . $transaction->id . ':pending',
                        'payment-transaction-gateway-session:' . $transaction->id,
                    ];
                    if (in_array($transaction->status, ['success', 'failed', 'cancelled'], true)) {
                        $keys[] = 'payment-transaction-status:' . $transaction->id . ':' . $transaction->status;
                    }
                    $sourceRows += count($keys);
                    $matched += DB::table('booking_history_events')->whereIn('idempotency_key', $keys)->count();
                }
            });

        return ['payment transaction lifecycle events since coverage start', $sourceRows, $matched, max(0, $sourceRows - $matched)];
    }

    private function postTripMaintenanceParity(): ?array
    {
        $coverageStart = config('booking_history.coverage_start');
        if (!Schema::hasTable('booking_activities') || !$coverageStart) return null;

        $expected = 0;
        $matched = 0;
        DB::table('booking_activities')->whereNotNull('booking_id')->where('event_key', 'post_trip_maintenance')
            ->where(function ($query) use ($coverageStart): void {
                $query->where('created_at', '>=', $coverageStart)->orWhere('updated_at', '>=', $coverageStart);
            })->orderBy('id')->chunkById(200, function ($activities) use ($coverageStart, &$expected, &$matched): void {
                foreach ($activities as $activity) {
                    $states = [];
                    if ($activity->created_at && $activity->created_at >= $coverageStart) $states[] = 'pending';
                    if ($activity->result_status === 'completed' && $activity->updated_at && $activity->updated_at >= $coverageStart) {
                        $states[] = 'completed';
                    }
                    foreach (array_unique($states) as $state) {
                        $expected++;
                        $matched += DB::table('booking_history_events')
                            ->where('source', 'booking_activities')
                            ->where('event_type', 'post_trip_maintenance')
                            ->where('idempotency_key', 'booking-activity:' . $activity->id . ':' . $state)
                            ->exists() ? 1 : 0;
                    }
                }
            });

        return ['post-trip maintenance transitions since coverage start', $expected, $matched, max(0, $expected - $matched)];
    }

    private function bookingInquiryParity(): ?array
    {
        $coverageStart = config('booking_history.coverage_start');
        if (!$coverageStart || !Schema::hasTable('booking_activities')) return null;

        return $this->sourceParity('booking_activities', [[
            'prefix' => 'booking-activity:',
            'source' => 'booking_activities',
            'event_type' => 'booking_inquiry_created',
        ]], 'booking_inquiry_created', 'event_at', $coverageStart);
    }
}
