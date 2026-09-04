<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Sales\SalesKpiSnapshot;
use App\Models\Sales\SalesKpiSnapshotRow;
use App\Models\Sales\SalesMetricFact;
use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesTargetVersion;
use App\Support\Foundation\CanonicalJson;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesPeriodCloseService
{
    public function __construct(
        private readonly SalesPerformanceService $performance,
        private readonly SalesAlertPolicyContract $alertPolicyContract,
        private readonly DomainEventPublisher $events,
    ) {}

    public function preview(string $companyId, string $periodStart, string $cutoffAt): array
    {
        abort_unless(config('sales.features.performance_snapshots', false), 409,
            'Sales performance snapshot writes are not enabled.');
        $timezone = config('sales.business_timezone');
        abort_unless(is_string($timezone) && $timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(), true),
            409, 'An approved Sales business timezone is required before a performance period can close.');
        $start = CarbonImmutable::parse($periodStart, $timezone)->startOfDay();
        abort_unless($start->isStartOfMonth(), 422, 'A Sales close period must start on the first calendar day of a month.');
        $end = $start->endOfMonth()->startOfDay();
        $endExclusiveUtc = $end->addDay()->startOfDay()->utc();
        $cutoff = CarbonImmutable::parse($cutoffAt)->utc();
        abort_if($cutoff->isFuture(), 422, 'The Sales close cutoff cannot be in the future.');

        $lock = DB::table('domain_period_locks')->where('domain', 'sales')->where('company_id', $companyId)
            ->where('period_type', 'month')->where('period_start', $start->utc())
            ->where('period_end', $endExclusiveUtc)->first();
        $blockers = [];
        if ($cutoff->lt($endExclusiveUtc)) $blockers[] = ['code' => 'period_not_complete', 'count' => 1];
        if ($lock?->state === 'locked') $blockers[] = ['code' => 'period_already_locked', 'count' => 1];

        $profileMismatchCount = DB::table('sales_metric_facts as fact')
            ->leftJoin('sales_profiles as profile', 'profile.id', '=', 'fact.sales_profile_id')
            ->where('fact.company_id', $companyId)->whereBetween('fact.occurred_on', [$start->toDateString(), $end->toDateString()])
            ->where('fact.occurred_at', '<=', $cutoff)->where(fn ($query) => $query
                ->whereNull('profile.id')->orWhereColumn('profile.company_id', '!=', 'fact.company_id'))->count();
        if ($profileMismatchCount > 0) $blockers[] = ['code' => 'metric_profile_scope_mismatch', 'count' => $profileMismatchCount];
        $missingStaffCount = SalesProfile::query()->withTrashed()->where('company_id', $companyId)
            ->where('effective_from', '<', $endExclusiveUtc)->where(fn ($query) => $query
                ->whereNull('effective_until')->orWhere('effective_until', '>', $start->utc()))
            ->whereDoesntHave('staff')->count();
        if ($missingStaffCount > 0) $blockers[] = ['code' => 'profile_staff_missing', 'count' => $missingStaffCount];
        $duplicateTargetCount = DB::query()->fromSub(
            SalesTargetVersion::query()->selectRaw('sales_profile_id, count(*) duplicate_count')
                ->where('company_id', $companyId)->where('status', 'approved')
                ->whereDate('period_start', '<=', $end)->whereDate('period_end', '>=', $start)
                ->groupBy('sales_profile_id')->havingRaw('count(*) > 1'), 'duplicates'
        )->count();
        if ($duplicateTargetCount > 0) $blockers[] = ['code' => 'approved_target_ambiguous', 'count' => $duplicateTargetCount];
        $policies = DB::table('sales_alert_policy_versions')->where('company_id', $companyId)->where('status', 'approved')
            ->whereDate('effective_from', '<=', $start)->where(fn ($query) => $query
                ->whereNull('effective_until')->orWhereDate('effective_until', '>=', $end))->get();
        if ($policies->count() !== 1) $blockers[] = [
            'code' => $policies->isEmpty() ? 'approved_alert_policy_missing' : 'approved_alert_policy_ambiguous',
            'count' => $policies->count(),
        ];
        $rules = null;
        if ($policies->count() === 1) {
            $rules = json_decode((string) $policies->first()->rules, true);
            if (! is_array($rules) || ! $this->alertPolicyContract->isComplete($rules)) {
                $blockers[] = ['code' => 'approved_alert_policy_incomplete', 'count' => 1];
                $rules = null;
            } else {
                $rules = $this->alertPolicyContract->normalize($rules);
            }
        }
        $unlinkedFrozenCount = SalesKpiSnapshot::query()->where('company_id', $companyId)
            ->whereDate('period_start', $start)->whereDate('period_end', $end)
            ->where('status', 'frozen')->whereNull('period_lock_id')->count();
        if ($unlinkedFrozenCount > 0) $blockers[] = [
            'code' => 'legacy_frozen_snapshot_unlinked', 'count' => $unlinkedFrozenCount,
        ];

        $performancePreview = $this->performance->preview(
            $companyId, $start->toDateString(), $end->toDateString(), $cutoff->toIso8601String(), $rules,
        );
        $rows = $performancePreview['rows'];
        if ($performancePreview['aging_missing_lineage_count'] > 0) $blockers[] = [
            'code' => 'aging_profile_or_revision_lineage_missing',
            'count' => $performancePreview['aging_missing_lineage_count'],
        ];
        foreach (['collection_cohort_missing_count', 'commission_cohort_missing_count',
            'commission_category_missing_count'] as $missingField) {
            $missingCount = (int) collect($rows)->sum($missingField);
            if ($missingCount > 0) $blockers[] = [
                'code' => "metric_{$missingField}", 'count' => $missingCount,
            ];
        }
        $incompleteNetNewSales = collect($rows)->where('new_sales_value_state', '!=', 'complete')->count();
        if ($incompleteNetNewSales > 0) $blockers[] = [
            'code' => 'net_new_sales_lkr_incomplete', 'count' => $incompleteNetNewSales,
        ];
        $factTotals = DB::table('sales_metric_facts')->selectRaw(
            'metric_type, SUM(quantity) quantity, SUM(amount_lkr) amount_lkr, COUNT(*) fact_count'
        )->where('company_id', $companyId)->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])
            ->where('occurred_at', '<=', $cutoff)->groupBy('metric_type')->orderBy('metric_type')->get()
            ->map(fn ($row) => (array) $row)->values()->all();
        $rowTotals = [
            'new_sales_lkr' => $this->sum($rows, 'new_sales_lkr'),
            'gross_new_sales_lkr' => $this->sum($rows, 'gross_new_sales_lkr'),
            'new_sales_adjustment_lkr' => $this->sum($rows, 'new_sales_adjustment_lkr'),
            'net_new_sales_lkr' => $this->sum($rows, 'net_new_sales_lkr'),
            'eligible_collections_lkr' => $this->sum($rows, 'eligible_collections_lkr'),
            'commission_earned_lkr' => $this->sum($rows, 'commission_earned_lkr'),
            'new_bookings_count' => $this->sum($rows, 'new_bookings_count'),
            'new_customers_count' => $this->sum($rows, 'new_customers_count'),
            'activities_count' => $this->sum($rows, 'activities_count'),
        ];
        $factMap = collect($factTotals)->keyBy('metric_type');
        $comparisons = [
            'gross_new_sales_lkr' => [(float) ($factMap->get('new_sales')['amount_lkr'] ?? 0), $rowTotals['gross_new_sales_lkr']],
            'new_sales_adjustment_lkr' => [(float) ($factMap->get('new_sales_adjustment')['amount_lkr'] ?? 0), $rowTotals['new_sales_adjustment_lkr']],
            'net_new_sales_lkr' => [
                (float) ($factMap->get('new_sales')['amount_lkr'] ?? 0)
                    + (float) ($factMap->get('new_sales_adjustment')['amount_lkr'] ?? 0),
                $rowTotals['net_new_sales_lkr'],
            ],
            'eligible_collections_lkr' => [(float) ($factMap->get('eligible_collection')['amount_lkr'] ?? 0), $rowTotals['eligible_collections_lkr']],
            'commission_earned_lkr' => [(float) ($factMap->get('commission_earned')['amount_lkr'] ?? 0), $rowTotals['commission_earned_lkr']],
            'new_bookings_count' => [(float) ($factMap->get('new_sales')['quantity'] ?? 0), $rowTotals['new_bookings_count']],
            'new_customers_count' => [(float) ($factMap->get('new_customer')['quantity'] ?? 0), $rowTotals['new_customers_count']],
            'activities_count' => [(float) ($factMap->get('sales_activity')['quantity'] ?? 0), $rowTotals['activities_count']],
        ];
        $deltas = collect($comparisons)->map(fn ($values) => round($values[0] - $values[1], 4))->all();
        if (collect($deltas)->contains(fn ($delta) => abs($delta) > 0.0001)) {
            $blockers[] = ['code' => 'metric_row_reconciliation_failed', 'count' => collect($deltas)->filter(fn ($delta) => abs($delta) > 0.0001)->count()];
        }
        $factChecksums = SalesMetricFact::query()->where('company_id', $companyId)
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])->where('occurred_at', '<=', $cutoff)
            ->orderBy('occurred_at')->orderBy('id')->pluck('fact_checksum')->all();
        $targetChecksums = SalesTargetVersion::query()->where('company_id', $companyId)->where('status', 'approved')
            ->whereDate('period_start', '<=', $end)->whereDate('period_end', '>=', $start)
            ->orderBy('sales_profile_id')->orderBy('period_start')->pluck('payload_checksum')->all();
        $reconciliation = [
            'fact_totals' => $factTotals, 'row_totals' => $rowTotals, 'deltas' => $deltas,
            'fact_count' => count($factChecksums), 'fact_checksum' => hash('sha256', implode('|', $factChecksums)),
            'target_count' => count($targetChecksums), 'target_checksum' => hash('sha256', implode('|', $targetChecksums)),
            'row_count' => count($rows), 'row_checksum' => hash('sha256', CanonicalJson::encode($rows)),
            'task_intervention_checksum' => hash('sha256', implode('|', collect($rows)
                ->pluck('task_intervention_evidence_checksum')->filter()->sort()->values()->all())),
            'task_deadline_or_history_missing_count' => collect($rows)
                ->max('task_deadline_or_history_missing_count'),
            'aging_source_count' => (int) collect($rows)->sum('aging_schedule_count'),
            'aging_source_checksum' => hash('sha256', implode('|', collect($rows)
                ->pluck('aging_source_checksum')->filter()->sort()->values()->all())),
            'aging_missing_lineage_count' => $performancePreview['aging_missing_lineage_count'],
            'alert_policy_version_id' => $policies->count() === 1 ? $policies->first()->id : null,
            'blockers' => $blockers,
        ];
        $snapshot = [
            'company_id' => $companyId, 'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(), 'period_start_utc' => $start->utc()->toIso8601String(),
            'period_end_exclusive_utc' => $endExclusiveUtc->toIso8601String(), 'business_timezone' => $timezone,
            'cutoff_at' => $cutoff->toIso8601String(), 'period_lock_id' => $lock?->id,
            'expected_lock_version' => (int) ($lock?->lock_version ?? 0),
            'action' => $lock?->state === 'reopened' ? 'rebuild' : 'close',
            'reconciliation' => $reconciliation,
        ];

        return [...$snapshot, 'rows' => $rows, 'can_close' => $blockers === [],
            'preview_checksum' => hash('sha256', CanonicalJson::encode($snapshot)), 'write_performed' => false];
    }

    public function close(
        string $companyId, string $periodStart, string $cutoffAt, int $expectedLockVersion,
        string $previewChecksum, string $reason, string $idempotencyKey, string $actorUserId,
    ): object {
        $preview = $this->preview($companyId, $periodStart, $cutoffAt);
        $requestChecksum = hash('sha256', CanonicalJson::encode([
            'company_id' => $companyId, 'period_start' => $preview['period_start'], 'cutoff_at' => $preview['cutoff_at'],
            'expected_lock_version' => $expectedLockVersion, 'preview_checksum' => $previewChecksum,
            'reason' => trim($reason), 'actor_user_id' => $actorUserId,
        ]));
        return DB::transaction(function () use (
            $companyId, $periodStart, $cutoffAt, $expectedLockVersion, $previewChecksum,
            $reason, $idempotencyKey, $actorUserId, $requestChecksum
        ): object {
            DB::table('companies')->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $existing = DB::table('sales_period_close_events')->where('company_id', $companyId)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_payload_checksum, $requestChecksum), 409,
                    'The period-close idempotency key was reused with different evidence.');
                return $this->result($existing->id);
            }
            $preview = $this->preview($companyId, $periodStart, $cutoffAt);
            abort_unless($preview['expected_lock_version'] === $expectedLockVersion, 409, 'The Sales period lock version is stale.');
            abort_unless(hash_equals($preview['preview_checksum'], $previewChecksum), 409,
                'The Sales period facts, targets, policy, or lock changed; refresh the close preview.');
            abort_unless($preview['can_close'], 409, 'Sales period reconciliation has unresolved blockers.');
            $startUtc = CarbonImmutable::parse($preview['period_start_utc']);
            $endExclusiveUtc = CarbonImmutable::parse($preview['period_end_exclusive_utc']);
            $lock = DB::table('domain_period_locks')->where('domain', 'sales')->where('company_id', $companyId)
                ->where('period_type', 'month')->where('period_start', $startUtc)->where('period_end', $endExclusiveUtc)
                ->lockForUpdate()->first();
            if (! $lock) {
                $lockId = (string) Str::uuid();
                DB::table('domain_period_locks')->insert([
                    'id' => $lockId, 'domain' => 'sales', 'company_id' => $companyId, 'period_type' => 'month',
                    'period_start' => $startUtc, 'period_end' => $endExclusiveUtc, 'state' => 'open', 'lock_version' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $lock = DB::table('domain_period_locks')->whereKey($lockId)->lockForUpdate()->first();
            }
            abort_unless((int) $lock->lock_version === $expectedLockVersion && in_array($lock->state, ['open', 'reopened'], true),
                409, 'The Sales period is not open for this close operation.');
            SalesMetricFact::query()->where('company_id', $companyId)
                ->whereBetween('occurred_on', [$preview['period_start'], $preview['period_end']])
                ->orderBy('occurred_at')->orderBy('id')->lockForUpdate()->get();
            SalesTargetVersion::query()->where('company_id', $companyId)
                ->whereDate('period_start', '<=', $preview['period_end'])->whereDate('period_end', '>=', $preview['period_start'])
                ->orderBy('sales_profile_id')->orderBy('version')->lockForUpdate()->get();
            $lockedPreview = $this->preview($companyId, $periodStart, $cutoffAt);
            abort_unless(hash_equals($lockedPreview['preview_checksum'], $previewChecksum), 409,
                'The Sales period evidence changed while closing; refresh the preview.');

            $prior = SalesKpiSnapshot::query()->where('period_lock_id', $lock->id)->where('status', 'frozen')
                ->lockForUpdate()->first();
            $kind = $lock->state === 'reopened' ? 'rebuild' : 'initial_close';
            $snapshot = $this->createSnapshot($lockedPreview, $lock->id, $prior?->id, $kind, $idempotencyKey, $requestChecksum, $actorUserId);
            if ($prior) $prior->update(['status' => 'superseded']);
            $version = (int) $lock->lock_version + 1;
            DB::table('domain_period_locks')->whereKey($lock->id)->update([
                'state' => 'locked', 'lock_version' => $version, 'reason' => trim($reason),
                'locked_by' => $actorUserId, 'locked_at' => now(), 'updated_at' => now(),
            ]);
            $eventId = $this->event($companyId, $lock->id, $snapshot->id, $kind === 'rebuild' ? 'rebuilt' : 'closed',
                $lock->state, 'locked', $version, $reason, $lockedPreview['reconciliation'],
                $previewChecksum, $requestChecksum, $idempotencyKey, $actorUserId);
            $this->events->record('sales', $companyId, 'sales_kpi_snapshot', $snapshot->id,
                $kind === 'rebuild' ? 'sales.performance.period_rebuilt' : 'sales.performance.period_closed',
                1, 1, ['period_lock_id' => $lock->id, 'period_lock_version' => $version,
                    'period_start' => $lockedPreview['period_start'],
                    'period_end' => $lockedPreview['period_end'], 'snapshot_checksum' => $snapshot->snapshot_checksum,
                    'reconciliation_checksum' => $snapshot->source_reconciliation_checksum], now(), $idempotencyKey);
            return $this->result($eventId);
        }, 3);
    }

    public function reopen(
        string $periodLockId, int $expectedVersion, string $reason, string $idempotencyKey, string $actorUserId,
    ): object {
        return DB::transaction(function () use ($periodLockId, $expectedVersion, $reason, $idempotencyKey, $actorUserId): object {
            $lock = DB::table('domain_period_locks')->whereKey($periodLockId)->where('domain', 'sales')->first();
            abort_unless($lock, 404);
            DB::table('companies')->whereKey($lock->company_id)->lockForUpdate()->firstOrFail();
            $lock = DB::table('domain_period_locks')->whereKey($periodLockId)->where('domain', 'sales')->lockForUpdate()->first();
            abort_unless($lock, 404);
            $requestChecksum = hash('sha256', CanonicalJson::encode([
                'period_lock_id' => $periodLockId, 'expected_version' => $expectedVersion,
                'reason' => trim($reason), 'actor_user_id' => $actorUserId,
            ]));
            $existing = DB::table('sales_period_close_events')->where('company_id', $lock->company_id)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_payload_checksum, $requestChecksum), 409,
                    'The period-reopen idempotency key was reused with different evidence.');
                return $this->result($existing->id);
            }
            abort_unless($lock->state === 'locked', 409, 'Only a locked Sales period can be reopened.');
            abort_unless((int) $lock->lock_version === $expectedVersion, 409, 'The Sales period lock version is stale.');
            $version = $expectedVersion + 1;
            DB::table('domain_period_locks')->whereKey($lock->id)->update([
                'state' => 'reopened', 'lock_version' => $version, 'reason' => trim($reason),
                'reopened_by' => $actorUserId, 'reopened_at' => now(), 'updated_at' => now(),
            ]);
            $eventId = $this->event($lock->company_id, $lock->id, null, 'reopened', 'locked', 'reopened',
                $version, $reason, null, null, $requestChecksum, $idempotencyKey, $actorUserId);
            $this->events->record('sales', $lock->company_id, 'sales_period_lock', $lock->id,
                'sales.performance.period_reopened', $version, 1, ['reason' => trim($reason)], now(), $idempotencyKey);
            return $this->result($eventId);
        }, 3);
    }

    private function createSnapshot(array $preview, string $lockId, ?string $priorId, string $kind, string $key, string $requestChecksum, string $actor): SalesKpiSnapshot
    {
        $version = (int) SalesKpiSnapshot::query()->where('company_id', $preview['company_id'])
            ->whereDate('period_start', $preview['period_start'])->whereDate('period_end', $preview['period_end'])
            ->lockForUpdate()->max('version') + 1;
        $header = [
            'company_id' => $preview['company_id'], 'period_lock_id' => $lockId, 'supersedes_snapshot_id' => $priorId,
            'period_start' => $preview['period_start'], 'period_end' => $preview['period_end'], 'period_type' => 'month',
            'cutoff_at' => $preview['cutoff_at'], 'status' => 'frozen', 'generation_kind' => $kind, 'version' => $version,
            'ranking_policy_snapshot' => SalesPerformanceService::RANKING_POLICY,
            'source_reconciliation_snapshot' => $preview['reconciliation'],
            'source_reconciliation_checksum' => hash('sha256', CanonicalJson::encode($preview['reconciliation'])),
            'generation_idempotency_key' => $key, 'generation_payload_checksum' => $requestChecksum,
            'generated_by' => $actor, 'generated_at' => now(),
        ];
        $header['snapshot_checksum'] = hash('sha256', CanonicalJson::encode([$header, $preview['rows']]));
        $snapshot = SalesKpiSnapshot::create($header);
        foreach ($preview['rows'] as $row) {
            $payload = $row + ['snapshot_id' => $snapshot->id, 'metric_snapshot' => $row];
            $payload['row_checksum'] = hash('sha256', CanonicalJson::encode($row));
            SalesKpiSnapshotRow::create($payload);
        }
        return $snapshot->load('rows');
    }

    private function event(string $companyId, string $lockId, ?string $snapshotId, string $action, string $from,
        string $to, int $version, string $reason, ?array $reconciliation, ?string $previewChecksum,
        string $requestChecksum, string $key, string $actor): string
    {
        $id = (string) Str::uuid();
        DB::table('sales_period_close_events')->insert([
            'id' => $id, 'company_id' => $companyId, 'period_lock_id' => $lockId, 'snapshot_id' => $snapshotId,
            'action' => $action, 'from_state' => $from, 'to_state' => $to, 'lock_version' => $version,
            'reason' => trim($reason), 'reconciliation_snapshot' => $reconciliation ? json_encode($reconciliation, JSON_THROW_ON_ERROR) : null,
            'reconciliation_checksum' => $reconciliation ? hash('sha256', CanonicalJson::encode($reconciliation)) : null,
            'preview_checksum' => $previewChecksum, 'request_payload_checksum' => $requestChecksum,
            'idempotency_key' => $key, 'actor_user_id' => $actor, 'occurred_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function result(string $eventId): object
    {
        $event = DB::table('sales_period_close_events')->whereKey($eventId)->firstOrFail();
        $event->period_lock = DB::table('domain_period_locks')->whereKey($event->period_lock_id)->first();
        $event->snapshot = $event->snapshot_id ? SalesKpiSnapshot::query()->with('rows')->find($event->snapshot_id) : null;
        unset($event->request_payload_checksum, $event->idempotency_key);
        return $event;
    }

    private function sum(array $rows, string $key): float
    {
        return array_reduce($rows, fn (float $sum, array $row) => $sum + (float) ($row[$key] ?? 0), 0.0);
    }
}
