<?php

namespace App\Console\Commands;

use App\Models\Finance\FinancialAuditEvent;
use App\Services\BookingHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BackfillBookingHistory extends Command
{
    protected $signature = 'booking-history:backfill {--before= : Reconstruct only source events before this UTC timestamp} {--commit : Write reconstructed history rows}';

    protected $description = 'Backfill verifiable legacy sources into booking history.';

    public function handle(BookingHistoryService $history): int
    {
        $requiredTables = [
            'booking_history_events', 'booking_statuses', 'booking_approvals', 'booking_activities',
            'financial_audit_events', 'sales_booking_attribution_events', 'sales_booking_attributions',
        ];
        $missingTables = array_values(array_filter($requiredTables, fn (string $table): bool => !Schema::hasTable($table)));
        if ($missingTables !== []) {
            $this->error('Backfill requires these tables before it can run: ' . implode(', ', $missingTables));
            return self::FAILURE;
        }

        if ($this->option('commit') && !$this->option('before')) {
            $this->error('--before is required with --commit to define the legacy coverage cutoff.');
            return self::FAILURE;
        }

        try {
            $before = $this->option('before')
                ? CarbonImmutable::parse($this->option('before'))->utc()
                : now()->toImmutable()->utc();
        } catch (\Throwable) {
            $this->error('--before must be a valid timestamp.');
            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');
        $this->line(($commit ? 'Writing' : 'Previewing') . ' reconstructed booking history before ' . $before->toIso8601String());
        $statusCount = $this->backfillStatusEvents($before, $commit);
        $approvalCount = $this->backfillApprovalDecisionEvents($before, $commit);
        $priceCount = $this->backfillPriceEvents($before, $commit);
        $financialCount = $this->backfillFinancialEvents($before, $commit, $history);
        $salesAttributionCount = $this->backfillSalesAttributionEvents($before, $commit, $history);
        $this->info("Booking status: {$statusCount}; approval decision: {$approvalCount}; trip price: {$priceCount}; financial: {$financialCount}; sales attribution: {$salesAttributionCount} source event(s)." . ($commit ? ' Existing idempotency keys were preserved.' : ' Run again with --commit and --before=<same timestamp> to write them.'));

        return self::SUCCESS;
    }

    private function backfillApprovalDecisionEvents(CarbonImmutable $before, bool $commit): int
    {
        $count = 0;
        DB::table('booking_approvals')->whereNotNull('booking_id')->orderBy('id')->chunkById(200, function ($rows) use ($before, $commit, &$count): void {
            foreach ($rows as $row) {
                $conditions = is_array($row->conditions)
                    ? $row->conditions
                    : (json_decode((string) ($row->conditions ?? ''), true) ?: []);
                $decisions = is_array($conditions['step_decisions'] ?? null) ? $conditions['step_decisions'] : [];
                foreach ($decisions as $decision) {
                    if (empty($decision['user_id']) || empty($decision['decided_at'])) continue;
                    try {
                        $decidedAt = CarbonImmutable::parse($decision['decided_at'])->utc();
                    } catch (\Throwable) {
                        continue;
                    }
                    if ($decidedAt->greaterThanOrEqualTo($before)) continue;
                    $step = max(1, (int) ($decision['step'] ?? 1));
                    $userId = (string) $decision['user_id'];
                    $idempotencyKey = 'booking-approval-decision:' . $row->id . ':' . $step . ':' . $userId;
                    $count++;
                    if (!$commit) continue;
                    $this->insertLegacy([
                        'booking_id' => (string) $row->booking_id,
                        'booking_item_id' => null,
                        'entity_type' => 'BookingApprovalDecision',
                        'entity_id' => (string) $row->id . ':' . $step . ':' . $userId,
                        'event_type' => 'booking_approval_approved',
                        'actor_type' => 'user',
                        'actor_user_id' => $userId,
                        'actor_display_snapshot' => null,
                        'source' => 'legacy:booking_approvals.conditions.step_decisions',
                        'reason' => $decision['comments'] ?? null,
                        'changes' => ['decision' => ['from' => null, 'to' => 'approved']],
                        'context' => ['approval_id' => (string) $row->id, 'approval_type' => $row->approval_type ?? null, 'step' => $step],
                        'correlation_id' => $idempotencyKey,
                        'idempotency_key' => $idempotencyKey,
                        'occurred_at' => $decidedAt,
                    ]);
                }

                $status = (string) ($row->status ?? '');
                $decidedAtValue = $status === 'rejected' ? ($row->rejected_at ?? null) : ($status === 'approved' ? ($row->approved_at ?? null) : null);
                $approverId = $row->approver_id ?? null;
                if (!in_array($status, ['approved', 'rejected'], true) || !$decidedAtValue || !$approverId) continue;
                try {
                    $decidedAt = CarbonImmutable::parse($decidedAtValue)->utc();
                } catch (\Throwable) {
                    continue;
                }
                if ($decidedAt->greaterThanOrEqualTo($before)) continue;
                $decisionName = $status === 'approved' ? 'approved' : 'rejected';
                $step = max(1, count($decisions) + 1);
                $idempotencyKey = 'booking-approval-decision:' . $row->id . ':' . $step . ':' . $approverId;
                if ($status === 'approved' && collect($decisions)->contains(fn ($decision) => (string) ($decision['user_id'] ?? '') === (string) $approverId)) continue;
                $count++;
                if (!$commit) continue;
                $this->insertLegacy([
                    'booking_id' => (string) $row->booking_id,
                    'booking_item_id' => null,
                    'entity_type' => 'BookingApprovalDecision',
                    'entity_id' => (string) $row->id . ':' . $step . ':' . $approverId,
                    'event_type' => 'booking_approval_' . $decisionName,
                    'actor_type' => 'user',
                    'actor_user_id' => (string) $approverId,
                    'actor_display_snapshot' => null,
                    'source' => 'legacy:booking_approvals',
                    'reason' => $row->comments,
                    'changes' => ['decision' => ['from' => null, 'to' => $decisionName]],
                    'context' => ['approval_id' => (string) $row->id, 'approval_type' => $row->approval_type ?? null, 'step' => $step],
                    'correlation_id' => $idempotencyKey,
                    'idempotency_key' => $idempotencyKey,
                    'occurred_at' => $decidedAt,
                ]);
            }
        });

        return $count;
    }

    private function backfillStatusEvents(CarbonImmutable $before, bool $commit): int
    {
        $query = DB::table('booking_statuses')->whereNotNull('booking_id')->where('created_at', '<', $before);
        $count = 0;
        $query->orderBy('created_at')->chunkById(200, function ($rows) use ($commit, &$count): void {
            foreach ($rows as $row) {
                $count++;
                if (!$commit) continue;
                $this->insertLegacy([
                    'booking_id' => (string) $row->booking_id,
                    'booking_item_id' => null,
                    'entity_type' => 'BookingStatus',
                    'entity_id' => (string) $row->id,
                    'event_type' => 'booking_status_changed',
                    'actor_type' => $row->created_user_id ? 'user' : 'system',
                    'actor_user_id' => $row->created_user_id,
                    'actor_display_snapshot' => null,
                    'source' => 'booking_statuses',
                    'reason' => null,
                    'changes' => ['status' => ['from' => $row->old_status, 'to' => $row->new_status]],
                    'context' => ['source_record_id' => (string) $row->id],
                    'correlation_id' => 'booking-status:' . $row->id,
                    'idempotency_key' => 'booking-status:' . $row->id,
                    'occurred_at' => $row->created_at,
                ]);
            }
        });

        return $count;
    }

    private function backfillPriceEvents(CarbonImmutable $before, bool $commit): int
    {
        $query = DB::table('booking_activities')->whereNotNull('booking_id')
            ->where('event_key', 'trip_price_changed')->where('event_at', '<', $before);
        $count = 0;
        $query->orderBy('event_at')->chunkById(200, function ($rows) use ($commit, &$count): void {
            foreach ($rows as $row) {
                $count++;
                if (!$commit) continue;
                $meta = is_array($row->meta) ? $row->meta : (json_decode((string) $row->meta, true) ?: []);
                $beforePrice = data_get($meta, 'previous_price');
                $afterPrice = data_get($meta, 'final_price');
                $changes = $beforePrice !== null || $afterPrice !== null
                    ? ['total_price' => ['from' => $beforePrice, 'to' => $afterPrice]]
                    : [];
                $this->insertLegacy([
                    'booking_id' => (string) $row->booking_id,
                    'booking_item_id' => $row->booking_item_id ? (string) $row->booking_item_id : null,
                    'entity_type' => 'BookingActivity',
                    'entity_id' => (string) $row->id,
                    'event_type' => 'booking_price_changed',
                    'actor_type' => (data_get($meta, 'actor_user_id') || data_get($meta, 'changed_by')) ? 'user' : 'system',
                    'actor_user_id' => data_get($meta, 'actor_user_id') ?? data_get($meta, 'changed_by'),
                    'actor_display_snapshot' => data_get($meta, 'changed_by_name'),
                    'source' => 'legacy:booking_activities',
                    'reason' => $row->detail,
                    'changes' => $changes,
                    'context' => ['currency' => data_get($meta, 'currency'), 'source_record_id' => (string) $row->id],
                    'correlation_id' => 'booking-price-activity:' . $row->id,
                    'idempotency_key' => 'legacy:booking-price:' . $row->id,
                    'occurred_at' => $row->event_at,
                ]);
            }
        });

        return $count;
    }

    private function backfillFinancialEvents(CarbonImmutable $before, bool $commit, BookingHistoryService $history): int
    {
        $count = 0;
        FinancialAuditEvent::query()->whereNotNull('booking_id')->where('occurred_at', '<', $before)
            ->orderBy('occurred_at')->chunkById(200, function ($rows) use ($commit, $history, &$count): void {
                foreach ($rows as $row) {
                    $count++;
                    if ($commit) $history->recordFinancialAuditEvent($row, 'reconstructed');
                }
            });

        return $count;
    }

    private function backfillSalesAttributionEvents(CarbonImmutable $before, bool $commit, BookingHistoryService $history): int
    {
        $count = 0;
        DB::table('sales_booking_attribution_events as event')
            ->join('sales_booking_attributions as attribution', 'attribution.id', '=', 'event.attribution_id')
            ->whereNotNull('attribution.booking_id')
            ->where('event.created_at', '<', $before)
            ->select('event.*', 'attribution.booking_id as booking_id')
            ->orderBy('event.id')
            ->chunkById(200, function ($rows) use ($commit, $history, &$count): void {
                foreach ($rows as $row) {
                    $count++;
                    if ($commit) $history->recordSalesAttributionEvent($row, (string) $row->booking_id, 'reconstructed');
                }
            }, 'event.id', 'id');

        return $count;
    }

    private function insertLegacy(array $event): void
    {
        $now = now()->utc();
        DB::table('booking_history_events')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            ...$event,
            'changes' => json_encode($event['changes'], JSON_THROW_ON_ERROR),
            'context' => json_encode($event['context'], JSON_THROW_ON_ERROR),
            'provenance' => 'reconstructed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
