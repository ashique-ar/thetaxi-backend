<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesProfile;
use App\Services\Sales\SalesAccessScope;
use App\Services\Sales\SalesCommissionStatusService;
use App\Services\Sales\SalesCollectionAgingStatusService;
use App\Services\Sales\SalesFrozenCollectionAgingService;
use App\Services\Sales\SalesMetricBreakdownService;
use App\Services\Sales\SalesPolicySettingsService;
use App\Services\Sales\SalesPerformanceService;
use App\Services\Sales\SalesPortfolioStatusService;
use App\Support\Foundation\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SalesDashboardController extends Controller
{
    public function __construct(
        private readonly SalesAccessScope $scope,
        private readonly SalesMetricBreakdownService $metricBreakdowns,
        private readonly SalesCommissionStatusService $commissionStatuses,
        private readonly SalesCollectionAgingStatusService $collectionAging,
        private readonly SalesFrozenCollectionAgingService $frozenAging,
        private readonly SalesPolicySettingsService $policySettings,
    ) {}

    public function show(Request $request, SalesPerformanceService $performance): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'], 'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'], 'include_resolved_alerts' => ['nullable', 'boolean'],
            'ranking_page' => ['nullable', 'integer', 'min:1'],
            'ranking_per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'pipeline_page' => ['nullable', 'integer', 'min:1'],
            'pipeline_per_page' => ['nullable', 'integer', 'min:1', 'max:25'],
            'dues_page' => ['nullable', 'integer', 'min:1'],
            'dues_per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'alerts_page' => ['nullable', 'integer', 'min:1'],
            'alerts_per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $ids = $this->scope->profileIds(
            $request->user(), 'sales.performance.view-all', 'sales.performance.view-team', $data['company_id'] ?? null,
        );
        /** @var SalesProfile|null $selectedProfile */
        $selectedProfile = $request->attributes->get('sales_dashboard_profile');
        $companyWide = $ids === null && $selectedProfile === null;
        $companyId = $selectedProfile?->company_id
            ?? $data['company_id']
            ?? SalesProfile::query()->whereIn('id', $ids ?? [])->value('company_id');
        abort_unless($companyId, 422, 'A legal entity is required for the Sales dashboard.');
        abort_unless(DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')->exists(), 422, 'Select an available legal entity.');
        abort_if($selectedProfile && isset($data['company_id']) && $data['company_id'] !== $selectedProfile->company_id,
            422, 'The requested legal entity does not match the selected Staff Sales Profile.');
        if ($selectedProfile) $ids = [$selectedProfile->id];
        elseif ($ids === null) $ids = SalesProfile::query()->where('company_id', $companyId)->pluck('id')->all();
        else {
            $ids = SalesProfile::query()->whereIn('id', $ids)->where('company_id', $companyId)->pluck('id')->all();
            abort_if(empty($ids), 403, 'Dashboard legal entity is outside your Sales scope.');
        }

        $asOf = now();
        $preview = $performance->preview($companyId, $data['from'], $data['to'], $asOf);
        $allRows = collect($preview['rows'])->whereIn('sales_profile_id', $ids)->values();
        $summaryFields = ['new_sales_lkr', 'gross_new_sales_lkr', 'new_sales_adjustment_lkr', 'net_new_sales_lkr',
            'new_booking_collections_lkr', 'existing_booking_collections_lkr',
            'eligible_collections_lkr', 'commission_new_business_lkr', 'commission_existing_business_lkr',
            'commission_earned_lkr',
            'commission_current_period_booking_lkr', 'commission_prior_period_booking_lkr',
            'commission_one_time_lkr', 'commission_long_term_lkr', 'new_bookings_count',
            'new_customers_count', 'activities_count', 'overdue_collections_lkr'];
        $summary = collect($summaryFields)->mapWithKeys(fn ($field) => [$field => (float) $allRows->sum($field)])->all();
        $netNewSalesComplete = $allRows->isNotEmpty()
            && $allRows->every(fn ($row) => $row['new_sales_value_state'] === 'complete');
        $summary['new_sales_value_state'] = $netNewSalesComplete ? 'complete' : 'incomplete';
        $summary['new_sales_adjustment_evidence_issue_count'] = (int) $allRows->sum('new_sales_adjustment_evidence_issue_count');
        if (! $netNewSalesComplete) {
            $summary['new_sales_lkr'] = null;
            $summary['new_sales_adjustment_lkr'] = null;
            $summary['net_new_sales_lkr'] = null;
        }
        $commissionStatus = $this->commissionStatuses->source(
            $companyId, $ids, $data['from'], $data['to'],
        );
        $summary = [...$summary, ...$commissionStatus['summary']];
        $agingStatus = $this->collectionAging->source($companyId, $ids, $data['from'], $data['to']);
        $summary = [...$summary, ...$agingStatus['summary']];
        foreach ([
            'collection_cohort' => ['state' => 'collection_cohort_state', 'missing' => 'collection_cohort_missing_count',
                'amounts' => ['new_booking_collections_lkr', 'existing_booking_collections_lkr']],
            'commission_cohort' => ['state' => 'commission_cohort_state', 'missing' => 'commission_cohort_missing_count',
                'amounts' => ['commission_current_period_booking_lkr', 'commission_prior_period_booking_lkr',
                    'commission_new_business_lkr', 'commission_existing_business_lkr']],
            'commission_category' => ['state' => 'commission_category_state', 'missing' => 'commission_category_missing_count',
                'amounts' => ['commission_one_time_lkr', 'commission_long_term_lkr']],
        ] as $key => $breakdown) {
            $complete = $allRows->isNotEmpty() && $allRows->every(fn ($row) => $row[$breakdown['state']] === 'complete');
            $summary["{$key}_state"] = $complete ? 'complete' : 'incomplete';
            $summary["{$key}_missing_count"] = (int) $allRows->sum($breakdown['missing']);
            if (! $complete) foreach ($breakdown['amounts'] as $field) $summary[$field] = null;
        }
        foreach ([
            'new_sales' => ['state' => 'new_sales_target_state', 'amount' => 'new_sales_target_lkr'],
            'eligible_collections' => ['state' => 'collection_target_state', 'amount' => 'collection_target_lkr'],
        ] as $key => $targetFields) {
            $states = $allRows->pluck($targetFields['state']);
            $complete = $allRows->isNotEmpty() && $states->every(fn ($state) => in_array($state, ['configured', 'configured_zero'], true));
            $summary["{$key}_target_state"] = $complete
                ? ($states->every(fn ($state) => $state === 'configured_zero') ? 'configured_zero' : 'configured')
                : ($states->contains('ambiguous_configuration') ? 'ambiguous_configuration'
                    : ($states->every(fn ($state) => $state === 'not_configured') ? 'not_configured' : 'partial_configuration'));
            $summary["{$key}_target_lkr"] = $complete ? (float) $allRows->sum($targetFields['amount']) : null;
            $summary["{$key}_target_missing_profile_count"] = $states
                ->filter(fn ($state) => ! in_array($state, ['configured', 'configured_zero'], true))->count();
        }
        $rankingPerPage = (int) ($data['ranking_per_page'] ?? 25);
        $rankingTotal = $allRows->count();
        $rankingLastPage = max(1, (int) ceil($rankingTotal / $rankingPerPage));
        $rankingPage = min((int) ($data['ranking_page'] ?? 1), $rankingLastPage);
        $rankingRows = $allRows->forPage($rankingPage, $rankingPerPage)->values();
        $ranking = [
            'data' => $rankingRows,
            'current_page' => $rankingPage,
            'per_page' => $rankingPerPage,
            'last_page' => $rankingLastPage,
            'total' => $rankingTotal,
            'from' => $rankingRows->isEmpty() ? null : (($rankingPage - 1) * $rankingPerPage) + 1,
            'to' => $rankingRows->isEmpty() ? null : (($rankingPage - 1) * $rankingPerPage) + $rankingRows->count(),
        ];

        $pipelineRows = DB::table('sales_opportunities')
            ->selectRaw("stage, COUNT(*) count,
                SUM(CASE WHEN expected_value_lkr IS NULL THEN 1 ELSE 0 END) missing_lkr_count,
                CASE WHEN COUNT(expected_value_lkr) = COUNT(*) THEN COALESCE(SUM(expected_value_lkr),0) ELSE NULL END expected_lkr,
                CASE WHEN COUNT(expected_value_lkr) = COUNT(*) THEN COALESCE(SUM(expected_value_lkr * probability_percent / 100),0) ELSE NULL END weighted_lkr")
            ->where('company_id', $companyId)->whereIn('owner_sales_profile_id', $ids)
            ->whereNotIn('stage', ['won', 'lost'])->groupBy('stage')
            ->orderByRaw("CASE stage WHEN 'new' THEN 1 WHEN 'contacted' THEN 2 WHEN 'qualified' THEN 3 WHEN 'quotation' THEN 4 WHEN 'negotiation' THEN 5 ELSE 6 END")
            ->orderBy('stage')->get();
        $pipeline = $this->paginateCollection(
            $pipelineRows, (int) ($data['pipeline_page'] ?? 1), (int) ($data['pipeline_per_page'] ?? 10),
        );
        $latestFrozenMonthly = DB::table('sales_kpi_snapshots')
            ->selectRaw('company_id, period_start, period_end, MAX(version) latest_version')
            ->where('status', 'frozen')->where('period_type', 'month')
            ->groupBy('company_id', 'period_start', 'period_end');
        $trends = DB::table('sales_kpi_snapshot_rows as row')
            ->join('sales_kpi_snapshots as snapshot', 'snapshot.id', '=', 'row.snapshot_id')
            ->joinSub($latestFrozenMonthly, 'latest', function ($join) {
                $join->on('latest.company_id', '=', 'snapshot.company_id')
                    ->on('latest.period_start', '=', 'snapshot.period_start')
                    ->on('latest.period_end', '=', 'snapshot.period_end')
                    ->on('latest.latest_version', '=', 'snapshot.version');
            })
            ->selectRaw('snapshot.id snapshot_id, snapshot.version snapshot_version, snapshot.period_start, snapshot.period_end, snapshot.cutoff_at, snapshot.snapshot_checksum, SUM(row.gross_new_sales_lkr) gross_new_sales_lkr, SUM(row.new_sales_adjustment_lkr) new_sales_adjustment_lkr, SUM(row.net_new_sales_lkr) net_new_sales_lkr, COUNT(*) new_sales_row_count, COUNT(row.net_new_sales_lkr) net_new_sales_row_count, SUM(row.eligible_collections_lkr) eligible_collections_lkr, SUM(row.commission_new_business_lkr + row.commission_existing_business_lkr) commission_lkr')
            ->where('snapshot.company_id', $companyId)->where('snapshot.status', 'frozen')->whereIn('row.sales_profile_id', $ids)
            ->whereDate('snapshot.period_end', '<=', $data['to'])
            ->groupBy('snapshot.id', 'snapshot.version', 'snapshot.period_start', 'snapshot.period_end',
                'snapshot.cutoff_at', 'snapshot.snapshot_checksum')
            ->orderByDesc('snapshot.period_end')->orderByDesc('snapshot.version')->limit(24)->get()
            ->sortBy([['period_end', 'asc'], ['snapshot_version', 'asc']])->values();
        $trendRows = DB::table('sales_kpi_snapshot_rows')->whereIn('snapshot_id', $trends->pluck('snapshot_id'))
            ->whereIn('sales_profile_id', $ids)->get(['snapshot_id', 'sales_profile_id', 'metric_snapshot'])->groupBy('snapshot_id');
        $trends = $trends->map(function ($trend) use ($trendRows) {
            $rows = $trendRows->get($trend->snapshot_id, collect());
            $snapshots = $rows->map(fn ($row) => is_string($row->metric_snapshot)
                ? (json_decode($row->metric_snapshot, true) ?: []) : ((array) $row->metric_snapshot));
            $trend->scope_profile_ids = $rows->pluck('sales_profile_id')->sort()->values()->all();
            $trend->new_sales_value_state = (int) $trend->new_sales_row_count > 0
                && (int) $trend->new_sales_row_count === (int) $trend->net_new_sales_row_count
                ? 'complete' : 'incomplete';
            $trend->new_sales_lkr = $trend->new_sales_value_state === 'complete'
                ? (float) $trend->net_new_sales_lkr : null;
            if ($trend->new_sales_value_state !== 'complete') {
                $trend->gross_new_sales_lkr = null;
                $trend->new_sales_adjustment_lkr = null;
                $trend->net_new_sales_lkr = null;
            }
            unset($trend->new_sales_row_count, $trend->net_new_sales_row_count);
            if ($snapshots->isNotEmpty()
                && $snapshots->every(fn (array $metrics) => array_key_exists('commission_earned_lkr', $metrics))) {
                $trend->commission_lkr = (float) $snapshots->sum(
                    fn (array $metrics) => (float) data_get($metrics, 'commission_earned_lkr', 0)
                );
            }
            foreach ([
                'collection_cohort' => [
                    'state' => 'collection_cohort_state', 'missing' => 'collection_cohort_missing_count',
                    'amounts' => ['current_period_booking_lkr' => 'new_booking_collections_lkr',
                        'prior_period_booking_lkr' => 'existing_booking_collections_lkr'],
                ],
                'commission_cohort' => [
                    'state' => 'commission_cohort_state', 'missing' => 'commission_cohort_missing_count',
                    'amounts' => ['current_period_booking_lkr' => 'commission_current_period_booking_lkr',
                        'prior_period_booking_lkr' => 'commission_prior_period_booking_lkr'],
                ],
                'commission_category' => [
                    'state' => 'commission_category_state', 'missing' => 'commission_category_missing_count',
                    'amounts' => ['one_time_lkr' => 'commission_one_time_lkr',
                        'long_term_lkr' => 'commission_long_term_lkr'],
                ],
            ] as $key => $definition) {
                $complete = $snapshots->isNotEmpty()
                    && $snapshots->every(fn (array $metrics) => data_get($metrics, $definition['state']) === 'complete');
                $stateAvailable = $snapshots->isNotEmpty()
                    && $snapshots->every(fn (array $metrics) => array_key_exists($definition['state'], $metrics));
                $trend->{$key.'_state'} = $complete ? 'complete' : 'incomplete';
                $trend->{$key.'_missing_count'} = $stateAvailable ? (int) $snapshots->sum(
                    fn (array $metrics) => (int) data_get($metrics, $definition['missing'], 0)
                ) : null;
                foreach ($definition['amounts'] as $responseField => $metricField) {
                    $trend->{$key.'_'.$responseField} = $complete
                        ? (float) $snapshots->sum(fn (array $metrics) => (float) data_get($metrics, $metricField, 0))
                        : null;
                }
            }
            $agingAvailable = $snapshots->isNotEmpty() && $snapshots->every(
                fn (array $metrics) => data_get($metrics, 'aging_snapshot_state') === 'complete'
                    && array_key_exists('aging_buckets', $metrics)
            );
            $trend->aging_snapshot_state = $agingAvailable ? 'complete' : 'incomplete';
            $trend->aging_schedule_count = $agingAvailable
                ? (int) $snapshots->sum(fn (array $metrics) => (int) data_get($metrics, 'aging_schedule_count', 0)) : null;
            $trend->aging_missing_lkr_count = $agingAvailable
                ? (int) $snapshots->sum(fn (array $metrics) => (int) data_get($metrics, 'aging_missing_lkr_count', 0)) : null;
            $trend->aging_lkr_state = ! $agingAvailable ? 'unavailable'
                : ($trend->aging_missing_lkr_count === 0 ? 'complete' : 'incomplete');
            $trend->aging_outstanding_lkr = $trend->aging_lkr_state === 'complete'
                ? round((float) $snapshots->sum(fn (array $metrics) => (float) data_get($metrics, 'aging_outstanding_lkr', 0)), 4) : null;
            $trend->aging_buckets = collect(SalesFrozenCollectionAgingService::BUCKETS)->mapWithKeys(
                function (string $bucket) use ($snapshots, $agingAvailable) {
                    if (! $agingAvailable) return [$bucket => null];
                    $missing = (int) $snapshots->sum(
                        fn (array $metrics) => (int) data_get($metrics, "aging_buckets.{$bucket}.missing_lkr_count", 0));
                    return [$bucket => [
                        'schedule_count' => (int) $snapshots->sum(
                            fn (array $metrics) => (int) data_get($metrics, "aging_buckets.{$bucket}.schedule_count", 0)),
                        'missing_lkr_count' => $missing,
                        'lkr_state' => $missing === 0 ? 'complete' : 'incomplete',
                        'outstanding_lkr' => $missing === 0 ? round((float) $snapshots->sum(
                            fn (array $metrics) => (float) data_get($metrics, "aging_buckets.{$bucket}.outstanding_lkr", 0)), 4) : null,
                    ]];
                }
            )->all();
            foreach ([
                'new_sales_target' => ['state' => 'new_sales_target_state', 'amount' => 'new_sales_target_lkr',
                    'actual' => 'net_new_sales_lkr'],
                'collection_target' => ['state' => 'collection_target_state', 'amount' => 'collection_target_lkr',
                    'actual' => 'eligible_collections_lkr'],
            ] as $key => $definition) {
                $states = $snapshots->map(fn (array $metrics) => data_get($metrics, $definition['state']));
                $configured = $states->isNotEmpty()
                    && $states->every(fn ($state) => in_array($state, ['configured', 'configured_zero'], true));
                $trend->{$key.'_state'} = $configured
                    ? ($states->every(fn ($state) => $state === 'configured_zero') ? 'configured_zero' : 'configured')
                    : ($states->contains('ambiguous_configuration') ? 'ambiguous_configuration'
                        : ($states->every(fn ($state) => $state === 'not_configured') ? 'not_configured' : 'partial_configuration'));
                $trend->{$key.'_lkr'} = $configured ? (float) $snapshots->sum(
                    fn (array $metrics) => (float) data_get($metrics, $definition['amount'], 0)) : null;
                $target = $trend->{$key.'_lkr'};
                $actualComplete = $key !== 'new_sales_target' || $trend->new_sales_value_state === 'complete';
                $trend->{$key.'_achievement_percent'} = $actualComplete && $target !== null && $target > 0
                    ? round(((float) $trend->{$definition['actual']}) / $target * 100, 4) : null;
            }

            return $trend;
        });
        $trends = $trends->values()->map(function ($trend, int $index) use ($trends) {
            $previous = $index > 0 ? $trends[$index - 1] : null;
            $state = 'comparable';
            if (! $previous) $state = 'no_prior_month';
            elseif (! CarbonImmutable::parse($previous->period_start)->addMonthNoOverflow()
                ->isSameDay(CarbonImmutable::parse($trend->period_start))) $state = 'non_consecutive_month';
            elseif ($previous->scope_profile_ids !== $trend->scope_profile_ids) $state = 'scope_changed';
            $trend->month_over_month_state = $state;
            foreach (['new_sales_lkr', 'eligible_collections_lkr', 'commission_lkr'] as $metric) {
                $valuesAvailable = $trend->{$metric} !== null && $previous->{$metric} !== null;
                $delta = $state === 'comparable' && $valuesAvailable
                    ? round((float) $trend->{$metric} - (float) $previous->{$metric}, 4) : null;
                $trend->{'month_over_month_'.$metric} = $delta === null ? null : [
                    'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
                    'delta_lkr' => $delta,
                    'delta_percent' => (float) $previous->{$metric} == 0.0 ? null
                        : round($delta / abs((float) $previous->{$metric}) * 100, 4),
                ];
            }
            unset($trend->scope_profile_ids);

            return $trend;
        });
        $scheduleSourceExpression = 'COALESCE(schedule.source_amount, schedule.amount)';
        $scheduleCurrencyExpression = "COALESCE(schedule.source_currency, 'LKR')";
        $scheduleOutstandingExpression = "{$scheduleSourceExpression} - COALESCE(paid.allocated,0)";
        $scheduleOutstandingLkrExpression = "CASE
            WHEN {$scheduleCurrencyExpression} = 'LKR' THEN {$scheduleOutstandingExpression}
            WHEN schedule.lkr_amount IS NOT NULL AND {$scheduleSourceExpression} > 0
                THEN schedule.lkr_amount * ({$scheduleOutstandingExpression}) / {$scheduleSourceExpression}
            ELSE NULL END";
        $upcomingQuery = DB::table('booking_payment_schedules as schedule')->join('bookings as booking', 'booking.id', '=', 'schedule.booking_id')
            ->join('sales_booking_attributions as attribution', 'attribution.booking_id', '=', 'booking.id')
            ->leftJoinSub(DB::table('booking_payment_schedule_allocations')->selectRaw('booking_payment_schedule_id, SUM(amount) allocated')->whereNull('deleted_at')->groupBy('booking_payment_schedule_id'), 'paid', fn ($join) => $join->on('paid.booking_payment_schedule_id', '=', 'schedule.id'))
            ->whereNull('schedule.deleted_at')->whereIn('attribution.collection_sales_profile_id', $ids)
            ->where('schedule.status', '!=', 'superseded')
            ->whereRaw("{$scheduleSourceExpression} > COALESCE(paid.allocated,0)")
            ->orderBy('schedule.due_date')->orderBy('schedule.id');
        $upcoming = $this->paginateQuery($upcomingQuery,
            ['schedule.id', 'booking.id as booking_id', 'booking.booking_number', 'schedule.due_date',
                DB::raw("{$scheduleSourceExpression} as scheduled_source_amount"),
                DB::raw('COALESCE(paid.allocated,0) as allocated_source_amount'),
                DB::raw("{$scheduleCurrencyExpression} as source_currency"),
                DB::raw("{$scheduleOutstandingExpression} as outstanding_source_amount"),
                DB::raw("{$scheduleOutstandingLkrExpression} as outstanding_lkr"),
                DB::raw("CASE WHEN ({$scheduleOutstandingLkrExpression}) IS NULL THEN 'missing' ELSE 'complete' END as lkr_state"),
                'attribution.collection_sales_profile_id'],
            (int) ($data['dues_page'] ?? 1), (int) ($data['dues_per_page'] ?? 10));
        $quality = [
            'held_attributions' => SalesBookingAttribution::query()->where('company_id', $companyId)->where('status', 'held')->where(fn ($q) => $q->whereIn('acquisition_sales_profile_id', $ids)->orWhereIn('collection_sales_profile_id', $ids))->count(),
            'held_commission_decisions' => DB::table('sales_commission_decisions')->where('company_id', $companyId)->whereIn('beneficiary_sales_profile_id', $ids)->whereIn('status', ['held', 'shadow_held'])->count(),
            'metric_freshness_at' => DB::table('sales_metric_facts')->where('company_id', $companyId)->whereIn('sales_profile_id', $ids)->max('created_at'),
            'latest_alert_suppressed_rule_count' => $companyWide && config('sales.features.performance_alert_evaluations', false)
                ? DB::table('sales_alert_evaluation_runs')->where('company_id', $companyId)->latest('evaluated_at')->value('suppressed_rule_count')
                : null,
        ];
        $alertEvidenceEnabled = config('sales.features.performance_alert_evaluations', false);
        $alertEvaluationsEnabled = $this->policySettings->featureEnabled((string) $companyId, 'performance_alert_evaluations');
        $alertActionEvidenceAvailable = config('sales.features.performance_alert_actions', false);
        $alertActionsEnabled = $this->policySettings->featureEnabled((string) $companyId, 'performance_alert_actions');
        $alertFields = ['alert.id', 'alert.sales_profile_id', 'staff.code as staff_code', 'alert.alert_type',
            'alert.severity', 'alert.status', 'alert.explanation', 'alert.detected_at', 'alert.assigned_to',
            'owner_staff.code as owner_staff_code'];
        if ($alertEvidenceEnabled) array_push($alertFields,
            'alert.threshold_snapshot', 'alert.comparison_snapshot', 'alert.policy_contract_snapshot');
        if ($alertActionEvidenceAvailable) array_push($alertFields, 'alert.event_version', 'alert.snoozed_until',
            'alert.escalation_level', 'alert.escalated_at', 'alert.last_action_at');
        $alertsQuery = DB::table('sales_performance_alerts as alert')
            ->join('sales_kpi_snapshots as alert_snapshot', 'alert_snapshot.id', '=', 'alert.snapshot_id')
            ->join('sales_profiles as profile', 'profile.id', '=', 'alert.sales_profile_id')
            ->join('staff', 'staff.id', '=', 'profile.staff_id')
            ->leftJoin('staff as owner_staff', function ($join) {
                $join->on('owner_staff.user_id', '=', 'alert.assigned_to')
                    ->on('owner_staff.company_id', '=', 'alert.company_id')->whereNull('owner_staff.deleted_at');
            })
            ->where('alert.company_id', $companyId)->whereIn('alert.sales_profile_id', $ids)
            ->where('alert_snapshot.status', 'frozen')
            ->whereIn('alert.status', ($data['include_resolved_alerts'] ?? false) ? ['open', 'acknowledged', 'resolved'] : ['open', 'acknowledged'])
            ->orderByRaw("CASE alert.severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->when($alertActionEvidenceAvailable, fn ($q) => $q->where(fn ($inner) => $inner->whereNull('alert.snoozed_until')->orWhere('alert.snoozed_until', '<=', now())))
            ->orderByDesc('alert.detected_at')->orderBy('alert.id');
        $alerts = $this->paginateQuery($alertsQuery, $alertFields,
            (int) ($data['alerts_page'] ?? 1), (int) ($data['alerts_per_page'] ?? 10),
            function ($alert) use ($alertEvidenceEnabled) {
                if (! $alertEvidenceEnabled) return $alert;
                foreach (['threshold_snapshot', 'comparison_snapshot', 'policy_contract_snapshot'] as $field) {
                    $alert->{$field} = is_string($alert->{$field}) ? json_decode($alert->{$field}, true) : $alert->{$field};
                }
                return $alert;
            });

        return response()->json(['status' => 'success', 'data' => ['period' => $data,
            'as_of' => $asOf->toIso8601String(), 'summary' => $summary, 'ranking_policy' => $preview['ranking_policy'],
            'rows' => $ranking, 'pipeline' => $pipeline, 'trends' => $trends,
            'drilldown_scope' => ['company_id' => $companyId,
                'sales_profile_id' => $selectedProfile?->id, 'currency' => 'LKR'],
            'upcoming_collections' => $upcoming, 'alerts' => $alerts,
            'alert_evaluations_enabled' => $alertEvaluationsEnabled,
            'alert_actions_enabled' => $alertActionsEnabled, 'data_quality' => $quality,
            'commission_status' => collect($commissionStatus)->except('rows')->all(),
            'collection_aging' => collect($agingStatus)->except('rows')->all()]]);
    }

    public function collectionAging(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'bucket' => ['nullable', Rule::in(['not_due', 'due_today', '1_30', '31_60', '61_90', '91_plus'])],
            'sales_profile_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $authorizedIds = $this->dashboardProfileIds(
            $request, $data['company_id'], $data['sales_profile_id'] ?? null,
            'Collection aging evidence is outside your current Sales scope.',
        );
        $source = $this->collectionAging->source(
            $data['company_id'], $authorizedIds, $data['from'], $data['to'],
        );
        abort_if($source['stock_state'] !== 'current', 409,
            'Historical collection aging reconstruction is unavailable; select a range containing the current business date.');
        $rows = isset($data['bucket'])
            ? $source['rows']->where('aging_bucket', $data['bucket'])->values() : $source['rows'];

        return response()->json(['status' => 'success', 'data' => [
            'scope' => ['company_id' => $data['company_id'],
                'sales_profile_id' => $data['sales_profile_id'] ?? null],
            'period' => ['from' => $data['from'], 'to' => $data['to']],
            'as_of' => $source['as_of'], 'timezone' => $source['timezone'],
            'stock_state' => $source['stock_state'], 'definition' => $source['definition'],
            'selected_bucket' => $data['bucket'] ?? null, 'summary' => $source['summary'],
            'buckets' => $source['buckets'],
            'schedules' => $this->paginateCollection(
                $rows, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25)),
            'canonical_record_access' => 'Booking Management requires the separate bookings.view permission.',
        ]]);
    }

    public function commissionStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'status' => ['required', Rule::in(['pending', 'held', 'approved', 'paid'])],
            'sales_profile_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $authorizedIds = $this->dashboardProfileIds(
            $request, $data['company_id'], $data['sales_profile_id'] ?? null,
            'Commission status evidence is outside your current Sales scope.',
        );
        $source = $this->commissionStatuses->source(
            $data['company_id'], $authorizedIds, $data['from'], $data['to'],
        );
        abort_if($data['status'] !== 'paid' && $source['stock_state'] !== 'current', 409,
            'Historical commission stock reconstruction is unavailable; select a range containing the current business date.');
        $summaryFields = [
            'pending' => ['amount' => 'pending_commission_lkr', 'count' => 'pending_statement_count'],
            'held' => ['amount' => 'held_commission_lkr', 'count' => 'held_decision_count'],
            'approved' => ['amount' => 'approved_unpaid_commission_lkr', 'count' => 'approved_statement_count'],
            'paid' => ['amount' => 'paid_commission_lkr', 'count' => 'paid_allocation_count'],
        ][$data['status']];

        return response()->json(['status' => 'success', 'data' => [
            'scope' => ['company_id' => $data['company_id'],
                'sales_profile_id' => $data['sales_profile_id'] ?? null, 'currency' => 'LKR'],
            'period' => ['from' => $data['from'], 'to' => $data['to']],
            'as_of' => $source['as_of'], 'stock_state' => $source['stock_state'],
            'definition' => $source['definitions'][$data['status']],
            'metric' => ['status' => $data['status'],
                'source_amount_lkr' => $source['summary'][$summaryFields['amount']],
                'source_count' => $source['summary'][$summaryFields['count']],
                'amount_state' => $data['status'] === 'held' ? $source['summary']['held_amount_state'] : 'complete',
                'held_eligible_basis_lkr' => $data['status'] === 'held'
                    ? $source['summary']['held_eligible_basis_lkr'] : null,
                'held_amount_missing_count' => $data['status'] === 'held'
                    ? $source['summary']['held_amount_missing_count'] : null],
            'records' => $this->paginateCollection(
                $source['rows'][$data['status']], (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25),
            ),
            'canonical_record_access' => 'Statements and payouts require their separately permissioned Sales commission routes.',
        ]]);
    }

    public function trendFacts(Request $request, string $snapshot): JsonResponse
    {
        $data = $request->validate([
            'metric' => ['required', Rule::in(['new_sales', 'eligible_collections', 'commission'])],
            'business_classification' => ['nullable', Rule::in(['new_business'])],
            'collection_cohort' => ['nullable', Rule::in(['current_period_booking', 'prior_period_booking'])],
            'commission_category' => ['nullable', Rule::in(['one_time', 'long_term'])],
            'sales_profile_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        abort_if($data['metric'] === 'new_sales'
            && ($data['business_classification'] ?? 'new_business') !== 'new_business', 422,
            'New Sales facts use the new-business classification only.');
        abort_if(isset($data['business_classification']) && $data['metric'] !== 'new_sales', 422,
            'Booking-level business classification is not a collection cohort or commission category.');
        abort_if(isset($data['collection_cohort']) && ! in_array($data['metric'], ['eligible_collections', 'commission'], true),
            422, 'Collection cohort applies only to collection or commission facts.');
        abort_if(isset($data['commission_category']) && $data['metric'] !== 'commission',
            422, 'Commission category applies only to commission facts.');
        abort_if(isset($data['business_classification'])
            && (isset($data['collection_cohort']) || isset($data['commission_category'])), 422,
            'Legacy business classification cannot be combined with report-relative cohort or commission category.');
        abort_if(isset($data['collection_cohort']) && isset($data['commission_category']), 422,
            'Frozen trend snapshots expose cohort and category as independent marginal totals, not an invented cross-tab.');
        $classification = $data['metric'] === 'new_sales' ? 'new_business' : null;

        $frozen = DB::table('sales_kpi_snapshots')->where('id', $snapshot)->where('status', 'frozen')->first();
        abort_unless($frozen, 404, 'Frozen Sales trend snapshot not found.');
        $authorizedIds = $this->dashboardProfileIds(
            $request, $frozen->company_id, $data['sales_profile_id'] ?? null,
            'Frozen Sales trend snapshot not found.',
        );

        $metricTypes = match ($data['metric']) {
            'new_sales' => ['new_sales', 'new_sales_adjustment'],
            'eligible_collections' => ['eligible_collection'],
            'commission' => ['commission_earned'],
        };
        $facts = DB::table('sales_metric_facts as fact')
            ->join('sales_profiles as profile', 'profile.id', '=', 'fact.sales_profile_id')
            ->join('staff', 'staff.id', '=', 'profile.staff_id')
            ->where('fact.company_id', $frozen->company_id)->whereIn('fact.sales_profile_id', $authorizedIds)
            ->whereIn('fact.metric_type', $metricTypes)
            ->whereBetween('fact.occurred_on', [$frozen->period_start, $frozen->period_end])
            ->where('fact.occurred_at', '<=', $frozen->cutoff_at)
            ->when($classification,
                fn ($query, string $classification) => $query->where('fact.business_classification', $classification))
             ->when($classification === null && in_array($data['metric'], ['eligible_collections', 'commission'], true),
                 fn ($query) => $query->whereIn('fact.business_classification', ['new_business', 'recurring_business']))
            ->orderByDesc('fact.occurred_on')->orderByDesc('fact.occurred_at')->orderBy('fact.id')->get([
                 'fact.id', 'fact.sales_profile_id', 'staff.code as staff_code', 'fact.metric_type',
                'fact.business_classification', 'fact.quantity', 'fact.amount_lkr', 'fact.occurred_on',
                'fact.occurred_at', 'fact.source_type', 'fact.source_id', 'fact.source_event',
                 'fact.dimensions', 'fact.fact_checksum',
            ]);
        $breakdownByFact = $this->metricBreakdowns->resolve(
            $facts, $frozen->company_id, $frozen->period_start, $frozen->period_end,
        );
        $rows = $facts->map(function ($fact) use ($breakdownByFact) {
                 $dimensions = is_string($fact->dimensions)
                    ? (json_decode($fact->dimensions, true) ?: []) : ((array) $fact->dimensions);
                $fact->canonical_ids = collect($dimensions)->only([
                    'booking_id', 'customer_id', 'receipt_id', 'payment_schedule_id',
                    'commission_decision_id', 'opportunity_id', 'task_id',
                 ])->filter(fn ($value) => is_string($value) && $value !== '')->all();
                 $fact->collection_cohort = data_get($breakdownByFact, "{$fact->id}.collection_cohort");
                 $fact->commission_category = data_get($breakdownByFact, "{$fact->id}.commission_category");
                 unset($fact->dimensions);

                 return $fact;
             });
        $cohortRelevant = in_array($data['metric'], ['eligible_collections', 'commission'], true);
        $categoryRelevant = $data['metric'] === 'commission';
        $cohortMissing = $cohortRelevant ? $rows->whereNull('collection_cohort')->count() : 0;
        $categoryMissing = $categoryRelevant ? $rows->whereNull('commission_category')->count() : 0;
        $rows = $rows
            ->when($data['collection_cohort'] ?? null,
                fn (Collection $source, string $cohort) => $source->where('collection_cohort', $cohort))
            ->when($data['commission_category'] ?? null,
                fn (Collection $source, string $category) => $source->where('commission_category', $category))
            ->values();
        $sourceAmount = (float) $rows->sum(fn ($fact) => (float) $fact->amount_lkr);
        $sourceQuantity = (float) $rows->sum(fn ($fact) => (float) $fact->quantity);
        $frozenRows = DB::table('sales_kpi_snapshot_rows')->where('snapshot_id', $frozen->id)
            ->whereIn('sales_profile_id', $authorizedIds)->get();
        $frozenTotals = $this->frozenTrendTotals(
            $frozenRows, $data['metric'],
            $data['collection_cohort'] ?? null, $data['commission_category'] ?? null,
        );
        $frozenAmount = $frozenTotals['amount_lkr'];
        $frozenQuantity = $frozenTotals['quantity'];
        $page = $this->paginateCollection(
            $rows, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25),
        );
        $breakdownIncomplete = (isset($data['collection_cohort']) && $cohortMissing > 0)
            || (isset($data['commission_category']) && $categoryMissing > 0)
            || $frozenTotals['state'] !== 'complete';
        return response()->json(['status' => 'success', 'data' => [
            'snapshot' => ['id' => $frozen->id, 'version' => (int) $frozen->version,
                'period_start' => $frozen->period_start, 'period_end' => $frozen->period_end,
                'cutoff_at' => $frozen->cutoff_at, 'snapshot_checksum' => $frozen->snapshot_checksum],
            'scope' => ['company_id' => $frozen->company_id,
                'sales_profile_id' => $data['sales_profile_id'] ?? null, 'currency' => 'LKR'],
             'metric' => ['key' => $data['metric'],
                 'business_classification' => $classification,
                 'collection_cohort' => $data['collection_cohort'] ?? null,
                 'commission_category' => $data['commission_category'] ?? null,
                 'source_amount_lkr' => $sourceAmount, 'frozen_amount_lkr' => $frozenAmount,
                 'source_quantity' => $sourceQuantity, 'frozen_quantity' => $frozenQuantity,
                 'reconciliation_status' => ! $breakdownIncomplete && $frozenAmount !== null
                    && abs($sourceAmount - $frozenAmount) < 0.0001
                     && ($frozenQuantity === null || abs($sourceQuantity - $frozenQuantity) < 0.0001)
                     ? 'matched' : ($breakdownIncomplete ? 'incomplete' : 'mismatch')],
             'breakdown' => [
                 'collection_cohort' => $cohortRelevant ? ['state' => $cohortMissing === 0 ? 'complete' : 'incomplete',
                     'missing_count' => $cohortMissing] : null,
                 'commission_category' => $categoryRelevant ? ['state' => $categoryMissing === 0 ? 'complete' : 'incomplete',
                     'missing_count' => $categoryMissing] : null,
                 'frozen_split_state' => $frozenTotals['state'],
             ],
             'facts' => $page,
            'canonical_record_access' => 'Requires the record-specific permission in addition to this scoped factual evidence.',
        ]]);
    }

    public function trendTargets(Request $request, string $snapshot): JsonResponse
    {
        $data = $request->validate([
            'sales_profile_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $frozen = DB::table('sales_kpi_snapshots')->where('id', $snapshot)
            ->where('status', 'frozen')->where('period_type', 'month')->first();
        abort_unless($frozen, 404, 'Frozen monthly Sales target point not found.');
        $authorizedIds = $this->dashboardProfileIds(
            $request, $frozen->company_id, $data['sales_profile_id'] ?? null,
            'Frozen monthly Sales target point not found.',
        );
        $rows = DB::table('sales_kpi_snapshot_rows as row')
            ->where('row.snapshot_id', $frozen->id)->whereIn('row.sales_profile_id', $authorizedIds)
            ->orderBy('row.staff_code')->orderBy('row.sales_profile_id')
            ->get(['row.sales_profile_id', 'row.staff_code', 'row.metric_snapshot', 'row.row_checksum'])
            ->map(function ($row) {
                $metrics = is_string($row->metric_snapshot)
                    ? (json_decode($row->metric_snapshot, true) ?: []) : ((array) $row->metric_snapshot);
                $versions = collect(data_get($metrics, 'target_configuration_snapshot.versions', []))
                    ->map(fn ($version) => collect((array) $version)->only([
                        'target_id', 'version', 'source', 'period_start', 'period_end', 'approved_at', 'payload_checksum',
                    ])->all())->values()->all();

                return (object) [
                    'sales_profile_id' => $row->sales_profile_id, 'staff_code' => $row->staff_code,
                    'new_sales_target_state' => data_get($metrics, 'new_sales_target_state', 'not_configured'),
                    'new_sales_target_lkr' => data_get($metrics, 'new_sales_target_lkr'),
                    'new_sales_value_state' => data_get($metrics, 'new_sales_value_state', 'incomplete'),
                    'new_sales_actual_lkr' => data_get($metrics, 'net_new_sales_lkr'),
                    'collection_target_state' => data_get($metrics, 'collection_target_state', 'not_configured'),
                    'collection_target_lkr' => data_get($metrics, 'collection_target_lkr'),
                    'collection_actual_lkr' => data_get($metrics, 'eligible_collections_lkr', 0),
                    'target_period_basis' => data_get($metrics, 'target_period_basis'),
                    'target_proration_applied' => data_get($metrics, 'target_proration_applied'),
                    'target_versions' => $versions, 'row_checksum' => $row->row_checksum,
                ];
            });
        $aggregate = [];
        foreach ([
            'new_sales' => ['state' => 'new_sales_target_state', 'target' => 'new_sales_target_lkr',
                'actual' => 'new_sales_actual_lkr'],
            'collection' => ['state' => 'collection_target_state', 'target' => 'collection_target_lkr',
                'actual' => 'collection_actual_lkr'],
        ] as $key => $fields) {
            $states = $rows->pluck($fields['state']);
            $complete = $states->isNotEmpty()
                && $states->every(fn ($state) => in_array($state, ['configured', 'configured_zero'], true));
            $state = $complete
                ? ($states->every(fn ($value) => $value === 'configured_zero') ? 'configured_zero' : 'configured')
                : ($states->contains('ambiguous_configuration') ? 'ambiguous_configuration'
                    : ($states->every(fn ($value) => $value === 'not_configured') ? 'not_configured' : 'partial_configuration'));
            $target = $complete ? round((float) $rows->sum($fields['target']), 4) : null;
            $actualComplete = $key !== 'new_sales'
                || $rows->every(fn ($row) => $row->new_sales_value_state === 'complete'
                    && $row->new_sales_actual_lkr !== null);
            $actual = $actualComplete ? round((float) $rows->sum($fields['actual']), 4) : null;
            $aggregate[$key] = ['state' => $state, 'target_lkr' => $target, 'actual_lkr' => $actual,
                'achievement_percent' => $actual !== null && $target !== null && $target > 0
                    ? round($actual / $target * 100, 4) : null];
        }

        return response()->json(['status' => 'success', 'data' => [
            'snapshot' => ['id' => $frozen->id, 'version' => $frozen->version,
                'period_start' => $frozen->period_start, 'period_end' => $frozen->period_end,
                'cutoff_at' => $frozen->cutoff_at, 'snapshot_checksum' => $frozen->snapshot_checksum],
            'scope' => ['company_id' => $frozen->company_id,
                'sales_profile_id' => $data['sales_profile_id'] ?? null, 'currency' => 'LKR'],
            'targets' => $aggregate,
            'rows' => $this->paginateCollection(
                $rows, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25)),
            'definition' => 'Frozen approved target lines and actuals for the exact authorized monthly snapshot rows; missing and approved zero remain distinct.',
        ]]);
    }

    public function trendAging(Request $request, string $snapshot): JsonResponse
    {
        $data = $request->validate([
            'sales_profile_id' => ['nullable', 'uuid'],
            'bucket' => ['nullable', Rule::in(SalesFrozenCollectionAgingService::BUCKETS)],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $frozen = DB::table('sales_kpi_snapshots')->where('id', $snapshot)
            ->where('status', 'frozen')->where('period_type', 'month')->first();
        abort_unless($frozen, 404, 'Frozen Sales aging snapshot not found.');
        $authorizedIds = $this->dashboardProfileIds(
            $request, $frozen->company_id, $data['sales_profile_id'] ?? null,
            'Frozen Sales aging snapshot not found.',
        );
        $source = $this->frozenAging->source(
            $frozen->company_id, $authorizedIds, $frozen->period_end, $frozen->cutoff_at,
        );
        $sourceTotals = $this->frozenAging->aggregate($source['rows']);
        $frozenRows = DB::table('sales_kpi_snapshot_rows')->where('snapshot_id', $frozen->id)
            ->whereIn('sales_profile_id', $authorizedIds)->get(['sales_profile_id', 'metric_snapshot']);
        $frozenTotals = $this->frozenAgingTotals($frozenRows);
        $sourceComparable = collect($sourceTotals)->except('aging_source_checksum')->all();
        $frozenComparable = $frozenTotals === null
            ? null : collect($frozenTotals)->except('aging_source_checksum')->all();
        $reconciliation = $source['missing_lineage_count'] > 0 || $frozenTotals === null
            ? 'incomplete'
            : (hash_equals(
                hash('sha256', CanonicalJson::encode($sourceComparable)),
                hash('sha256', CanonicalJson::encode($frozenComparable)),
            ) ? 'matched' : 'mismatch');
        $rows = isset($data['bucket'])
            ? $source['rows']->where('aging_bucket', $data['bucket'])->values() : $source['rows'];

        return response()->json(['status' => 'success', 'data' => [
            'snapshot' => ['id' => $frozen->id, 'version' => (int) $frozen->version,
                'period_start' => $frozen->period_start, 'period_end' => $frozen->period_end,
                'cutoff_at' => $frozen->cutoff_at, 'snapshot_checksum' => $frozen->snapshot_checksum],
            'scope' => ['company_id' => $frozen->company_id,
                'sales_profile_id' => $data['sales_profile_id'] ?? null, 'currency' => 'LKR'],
            'selected_bucket' => $data['bucket'] ?? null,
            'reconciliation_status' => $reconciliation,
            'missing_lineage_count' => $source['missing_lineage_count'],
            'source' => $sourceTotals, 'frozen' => $frozenTotals,
            'schedules' => $this->paginateCollection(
                $rows, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25)),
            'definition' => 'Frozen month-end schedule aging reconstructed from retained schedule revisions, attribution events, and net allocations known by the snapshot cutoff.',
            'canonical_record_access' => 'Booking and receipt records require their own permissions; this source exposes no customer contact or payment evidence.',
        ]]);
    }

    public function kpiFacts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'metric' => ['required', Rule::in([
                'new_sales', 'new_bookings', 'new_customers', 'eligible_collections', 'commission',
            ])],
            'collection_cohort' => ['nullable', Rule::in(['current_period_booking', 'prior_period_booking'])],
            'commission_category' => ['nullable', Rule::in(['one_time', 'long_term'])],
            'sales_profile_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        abort_if(isset($data['collection_cohort']) && ! in_array($data['metric'], ['eligible_collections', 'commission'], true),
            422, 'Collection cohort applies only to collection or commission facts.');
        abort_if(isset($data['commission_category']) && $data['metric'] !== 'commission',
            422, 'Commission category applies only to commission facts.');
        $authorizedIds = $this->dashboardProfileIds(
            $request, $data['company_id'], $data['sales_profile_id'] ?? null,
            'Sales KPI source is outside your current Sales scope.',
        );
        $metricTypes = match ($data['metric']) {
            'new_sales' => ['new_sales', 'new_sales_adjustment'],
            'new_bookings' => ['new_sales'],
            'new_customers' => ['new_customer'],
            'eligible_collections' => ['eligible_collection'],
            'commission' => ['commission_earned'],
        };
        $asOf = now();
        $facts = DB::table('sales_metric_facts as fact')
            ->join('sales_profiles as profile', 'profile.id', '=', 'fact.sales_profile_id')
            ->join('staff', 'staff.id', '=', 'profile.staff_id')
            ->where('fact.company_id', $data['company_id'])
            ->whereIn('fact.sales_profile_id', $authorizedIds)
            ->whereIn('fact.metric_type', $metricTypes)
            ->when(in_array($data['metric'], ['new_sales', 'new_bookings', 'new_customers'], true),
                fn ($query) => $query->where('fact.business_classification', 'new_business'))
            ->when(in_array($data['metric'], ['eligible_collections', 'commission'], true),
                fn ($query) => $query->whereIn('fact.business_classification', ['new_business', 'recurring_business']))
            ->whereBetween('fact.occurred_on', [$data['from'], $data['to']])
            ->where('fact.occurred_at', '<=', $asOf);
        $rows = $facts->orderByDesc('fact.occurred_on')->orderByDesc('fact.occurred_at')->orderBy('fact.id')->get([
                'fact.id', 'fact.sales_profile_id', 'staff.code as staff_code', 'fact.metric_type',
                'fact.business_classification', 'fact.quantity', 'fact.amount_lkr', 'fact.occurred_on',
                'fact.occurred_at', 'fact.source_type', 'fact.source_id', 'fact.source_event',
                'fact.dimensions', 'fact.fact_checksum',
            ]);
        $breakdownByFact = $this->metricBreakdowns->resolve(
            $rows, $data['company_id'], $data['from'], $data['to'],
        );
        $rows = $rows->map(function ($fact) use ($breakdownByFact) {
                $dimensions = is_string($fact->dimensions)
                    ? (json_decode($fact->dimensions, true) ?: []) : ((array) $fact->dimensions);
                $fact->canonical_ids = collect($dimensions)->only([
                    'booking_id', 'customer_id', 'receipt_id', 'payment_schedule_id',
                    'commission_decision_id', 'opportunity_id', 'task_id',
                ])->filter(fn ($value) => is_string($value) && $value !== '')->all();
                $fact->collection_cohort = data_get($breakdownByFact, "{$fact->id}.collection_cohort");
                $fact->commission_category = data_get($breakdownByFact, "{$fact->id}.commission_category");
                unset($fact->dimensions);

                return $fact;
            });
        $amountFor = fn (Collection $source, string $field, string $value) => (float) $source
            ->where($field, $value)->sum(fn ($fact) => (float) $fact->amount_lkr);
        $cohortRelevant = in_array($data['metric'], ['eligible_collections', 'commission'], true);
        $categoryRelevant = $data['metric'] === 'commission';
        $cohortMissing = $cohortRelevant ? $rows->whereNull('collection_cohort')->count() : 0;
        $categoryMissing = $categoryRelevant ? $rows->whereNull('commission_category')->count() : 0;
        $breakdown = [
            'collection_cohort' => $cohortRelevant ? [
                'state' => $cohortMissing === 0 ? 'complete' : 'incomplete',
                'missing_count' => $cohortMissing,
                'current_period_booking_lkr' => $cohortMissing === 0
                    ? $amountFor($rows, 'collection_cohort', 'current_period_booking') : null,
                'prior_period_booking_lkr' => $cohortMissing === 0
                    ? $amountFor($rows, 'collection_cohort', 'prior_period_booking') : null,
            ] : null,
            'commission_category' => $categoryRelevant ? [
                'state' => $categoryMissing === 0 ? 'complete' : 'incomplete',
                'missing_count' => $categoryMissing,
                'one_time_lkr' => $categoryMissing === 0
                    ? $amountFor($rows, 'commission_category', 'one_time') : null,
                'long_term_lkr' => $categoryMissing === 0
                    ? $amountFor($rows, 'commission_category', 'long_term') : null,
            ] : null,
        ];
        $filteredRows = $rows
            ->when($data['collection_cohort'] ?? null,
                fn (Collection $source, string $cohort) => $source->where('collection_cohort', $cohort))
            ->when($data['commission_category'] ?? null,
                fn (Collection $source, string $category) => $source->where('commission_category', $category))
            ->values();
        $sourceAmount = (float) $filteredRows->sum(fn ($fact) => (float) $fact->amount_lkr);
        $sourceQuantity = (float) $filteredRows->sum(fn ($fact) => (float) $fact->quantity);
        $unfilteredAmount = (float) $rows->sum(fn ($fact) => (float) $fact->amount_lkr);
        $unfilteredQuantity = (float) $rows->sum(fn ($fact) => (float) $fact->quantity);
        $page = $this->paginateCollection(
            $filteredRows, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25),
        );

        return response()->json(['status' => 'success', 'data' => [
            'scope' => ['company_id' => $data['company_id'],
                'sales_profile_id' => $data['sales_profile_id'] ?? null, 'currency' => 'LKR'],
            'period' => ['from' => $data['from'], 'to' => $data['to'],
                'date_contract' => 'inclusive business dates; signed event occurred_at must be at or before as_of'],
            'as_of' => $asOf->toIso8601String(),
            'metric' => ['key' => $data['metric'],
                'business_classification' => in_array($data['metric'], ['new_sales', 'new_bookings', 'new_customers'], true)
                    ? 'new_business' : null,
                'primary_measure' => in_array($data['metric'], ['new_bookings', 'new_customers'], true)
                    ? 'quantity' : 'amount_lkr',
                'source_amount_lkr' => $sourceAmount,
                'source_quantity' => $sourceQuantity,
                'unfiltered_source_amount_lkr' => $unfilteredAmount,
                'unfiltered_source_quantity' => $unfilteredQuantity,
                'collection_cohort' => $data['collection_cohort'] ?? null,
                'commission_category' => $data['commission_category'] ?? null,
                'flow_definition' => 'Signed factual events inside the selected period; booking/customer counts include traceable debit and credit corrections and are never point-in-time balances.'],
            'breakdown' => $breakdown,
            'facts' => $page,
            'canonical_record_access' => 'Requires the record-specific permission in addition to this scoped factual evidence.',
        ]]);
    }

    public function pipelineFacts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'stage' => ['required', Rule::in(['new', 'contacted', 'qualified', 'quotation', 'negotiation'])],
            'sales_profile_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $authorizedIds = $this->dashboardProfileIds(
            $request, $data['company_id'], $data['sales_profile_id'] ?? null,
            'Pipeline stage is outside your current Sales scope.',
        );
        $asOf = now();
        $query = DB::table('sales_opportunities as opportunity')
            ->join('sales_profiles as profile', 'profile.id', '=', 'opportunity.owner_sales_profile_id')
            ->join('staff', 'staff.id', '=', 'profile.staff_id')
            ->where('opportunity.company_id', $data['company_id'])
            ->whereIn('opportunity.owner_sales_profile_id', $authorizedIds)
            ->where('opportunity.stage', $data['stage']);
        $sourceCount = (clone $query)->count();
        $missingLkrCount = (clone $query)->whereNull('opportunity.expected_value_lkr')->count();
        $sourceExpected = $missingLkrCount === 0
            ? (float) (clone $query)->sum('opportunity.expected_value_lkr') : null;
        $weightedTotal = $missingLkrCount === 0
            ? (clone $query)->selectRaw(
                'COALESCE(SUM(opportunity.expected_value_lkr * opportunity.probability_percent / 100),0) weighted_lkr'
            )->first() : null;
        $sourceWeighted = $weightedTotal ? (float) $weightedTotal->weighted_lkr : null;

        $perPage = (int) ($data['per_page'] ?? 25);
        $lastPage = max(1, (int) ceil($sourceCount / $perPage));
        $page = min((int) ($data['page'] ?? 1), $lastPage);
        $rows = $query->orderByRaw('opportunity.expected_close_date IS NULL')
            ->orderBy('opportunity.expected_close_date')->orderByDesc('opportunity.created_at')->orderBy('opportunity.id')
            ->forPage($page, $perPage)->get([
                'opportunity.id', 'opportunity.opportunity_number', 'opportunity.stage',
                'opportunity.owner_sales_profile_id', 'staff.code as staff_code',
                'opportunity.expected_value_lkr', 'opportunity.probability_percent',
                'opportunity.expected_close_date',
            ])->map(function ($row) {
                $row->weighted_lkr = $row->expected_value_lkr === null ? null : round(
                    (float) $row->expected_value_lkr * (int) $row->probability_percent / 100, 4,
                );

                return $row;
            });

        return response()->json(['status' => 'success', 'data' => [
            'scope' => ['company_id' => $data['company_id'],
                'sales_profile_id' => $data['sales_profile_id'] ?? null, 'currency' => 'LKR'],
            'as_of' => $asOf->toIso8601String(),
            'stock_definition' => 'Current open opportunities in the selected stage; never summed across periods.',
            'stage' => $data['stage'],
            'source_totals' => ['count' => $sourceCount, 'lkr_state' => $missingLkrCount === 0 ? 'complete' : 'missing',
                'missing_lkr_count' => $missingLkrCount, 'expected_lkr' => $sourceExpected,
                'weighted_lkr' => $sourceWeighted],
            'opportunities' => $this->pageEnvelope($rows, $page, $perPage, $sourceCount, $lastPage),
            'canonical_record_access' => 'Opening the CRM workflow requires sales.crm.view in addition to this scoped performance evidence.',
        ]]);
    }

    public function scheduleFacts(Request $request, string $schedule): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'sales_profile_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $authorizedIds = $this->dashboardProfileIds(
            $request, $data['company_id'], $data['sales_profile_id'] ?? null,
            'Collection schedule is outside your current Sales scope.',
        );
        $paid = DB::table('booking_payment_schedule_allocations')
            ->selectRaw('booking_payment_schedule_id, SUM(amount) allocated')
            ->whereNull('deleted_at')->groupBy('booking_payment_schedule_id');
        $sourceExpression = 'COALESCE(schedule.source_amount, schedule.amount)';
        $currencyExpression = "COALESCE(schedule.source_currency, 'LKR')";
        $outstandingExpression = "{$sourceExpression} - COALESCE(paid.allocated,0)";
        $outstandingLkrExpression = "CASE
            WHEN {$currencyExpression} = 'LKR' THEN {$outstandingExpression}
            WHEN schedule.lkr_amount IS NOT NULL AND {$sourceExpression} > 0
                THEN schedule.lkr_amount * ({$outstandingExpression}) / {$sourceExpression}
            ELSE NULL END";
        $row = DB::table('booking_payment_schedules as schedule')
            ->join('bookings as booking', 'booking.id', '=', 'schedule.booking_id')
            ->join('sales_booking_attributions as attribution', 'attribution.booking_id', '=', 'booking.id')
            ->join('sales_profiles as profile', 'profile.id', '=', 'attribution.collection_sales_profile_id')
            ->join('staff', 'staff.id', '=', 'profile.staff_id')
            ->leftJoinSub($paid, 'paid', fn ($join) => $join->on('paid.booking_payment_schedule_id', '=', 'schedule.id'))
            ->where('schedule.id', $schedule)->whereNull('schedule.deleted_at')
            ->where('schedule.status', '!=', 'superseded')
            ->where('attribution.company_id', $data['company_id'])
            ->whereIn('attribution.collection_sales_profile_id', $authorizedIds)
            ->first([
                'schedule.id', 'schedule.booking_id', 'booking.booking_number', 'schedule.sequence',
                'schedule.label', 'schedule.due_date', 'schedule.status', 'schedule.schedule_kind',
                'schedule.is_collection_target_eligible', 'schedule.revision_number',
                'attribution.collection_sales_profile_id', 'staff.code as staff_code',
                DB::raw("{$sourceExpression} as scheduled_source_amount"),
                DB::raw('COALESCE(paid.allocated,0) as allocated_source_amount'),
                DB::raw("{$currencyExpression} as source_currency"),
                'schedule.lkr_amount as scheduled_lkr_amount',
                DB::raw("{$outstandingExpression} as outstanding_source_amount"),
                DB::raw("{$outstandingLkrExpression} as outstanding_lkr"),
            ]);
        abort_unless($row, 404, 'Collection schedule is outside your current Sales scope.');
        $row->lkr_state = $row->outstanding_lkr === null ? 'missing' : 'complete';

        $allocations = DB::table('booking_payment_schedule_allocations')
            ->where('booking_payment_schedule_id', $row->id)->whereNull('deleted_at')
            ->orderByDesc('allocated_at')->orderBy('id');
        $perPage = (int) ($data['per_page'] ?? 25);
        $total = (clone $allocations)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min((int) ($data['page'] ?? 1), $lastPage);
        $allocationRows = $allocations->forPage($page, $perPage)->get([
            'id', 'booking_payment_receipt_id', 'amount', 'allocated_at',
        ]);

        return response()->json(['status' => 'success', 'data' => [
            'scope' => ['company_id' => $data['company_id'],
                'sales_profile_id' => $data['sales_profile_id'] ?? null],
            'as_of' => now()->toIso8601String(),
            'balance_equation' => 'scheduled source amount - net source allocations = outstanding source amount',
            'schedule' => $row,
            'allocations' => $this->pageEnvelope($allocationRows, $page, $perPage, $total, $lastPage),
            'canonical_record_access' => 'Booking and receipt records require their own permissions in addition to this scoped schedule evidence.',
        ]]);
    }

    public function activePortfolio(Request $request, SalesPortfolioStatusService $portfolio): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'to' => ['required', 'date_format:Y-m-d'], 'sales_profile_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $authorizedIds = $this->dashboardProfileIds(
            $request, $data['company_id'], $data['sales_profile_id'] ?? null,
            'Active portfolio is outside your current Sales scope.',
        );

        return response()->json(['status' => 'success', 'data' => $portfolio->source(
            $data['company_id'], $authorizedIds, $data['to'],
            (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25),
        )]);
    }

    public function staff(Request $request, string $staffId, SalesPerformanceService $performance): JsonResponse
    {
        $scope = $request->validate(['company_id' => ['nullable', 'uuid', 'exists:companies,id']]);
        $companyId = $scope['company_id'] ?? null;
        $authorizedIds = $this->scope->profileIds(
            $request->user(), 'sales.performance.view-all', 'sales.performance.view-team', $companyId,
        );
        $profiles = SalesProfile::query()->where('staff_id', $staffId)
            ->when($companyId, fn ($query, string $id) => $query->where('company_id', $id))
            ->when($authorizedIds !== null, fn ($query) => $query->whereIn('id', $authorizedIds))
            ->orderByDesc('effective_from')->limit(2)->get();
        abort_if($profiles->isEmpty(), 404, 'Sales Staff dashboard not found.');
        abort_if($profiles->count() !== 1, 422,
            'The Staff record resolves to more than one Sales Profile; select the legal entity explicitly.');
        $profile = $profiles->first();
        $this->scope->assertProfile(
            $request->user(), $profile, 'sales.performance.view-all', 'sales.performance.view-team',
        );
        $request->attributes->set('sales_dashboard_profile', $profile);

        return $this->show($request, $performance);
    }

    public function portfolio(Request $request): JsonResponse
    {
        $ids = $this->scope->profileIds($request->user(), 'sales.performance.view-all', 'sales.performance.view-team');
        $query = SalesBookingAttribution::query()->with(['booking.customer.user'])
            ->where(fn ($q) => $ids === null ? $q : $q->whereIn('acquisition_sales_profile_id', $ids)->orWhereIn('collection_sales_profile_id', $ids));
        $page = $query->latest('secured_at')->paginate($request->integer('per_page', 25));
        return response()->json(['status' => 'success', 'data' => $page->through(fn (SalesBookingAttribution $row) => [
            'id' => $row->id, 'booking_id' => $row->booking_id, 'business_classification' => $row->business_classification,
            'commission_category' => $row->commission_category, 'secured_at' => $row->secured_at,
            'contract_value_lkr' => $row->contract_value_lkr, 'new_customer_status' => $row->new_customer_status,
            'booking' => $row->booking ? [
                'id' => $row->booking->id, 'booking_number' => $row->booking->booking_number,
                'status' => $row->booking->status, 'payment_status' => $row->booking->payment_status,
                'payment_collection_status' => $row->booking->payment_collection_status,
                'amount_to_pay' => $row->booking->amount_to_pay,
                'customer' => $row->booking->customer ? [
                    'id' => $row->booking->customer->id, 'code' => $row->booking->customer->code,
                    'name' => trim((string) ($row->booking->customer->user?->first_name.' '.$row->booking->customer->user?->last_name)),
                ] : null,
            ] : null,
        ])]);
    }

    private function paginateQuery(
        Builder $query,
        array $columns,
        int $requestedPage,
        int $perPage,
        ?callable $transform = null,
    ): array {
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($requestedPage, $lastPage);
        $rows = $query->forPage($page, $perPage)->get($columns);
        if ($transform) $rows = $rows->map($transform);

        return $this->pageEnvelope($rows, $page, $perPage, $total, $lastPage);
    }

    private function paginateCollection(Collection $rows, int $requestedPage, int $perPage): array
    {
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($requestedPage, $lastPage);

        return $this->pageEnvelope($rows->forPage($page, $perPage)->values(), $page, $perPage, $total, $lastPage);
    }

    private function pageEnvelope(Collection $rows, int $page, int $perPage, int $total, int $lastPage): array
    {
        return [
            'data' => $rows->values(), 'current_page' => $page, 'per_page' => $perPage,
            'last_page' => $lastPage, 'total' => $total,
            'from' => $rows->isEmpty() ? null : (($page - 1) * $perPage) + 1,
            'to' => $rows->isEmpty() ? null : (($page - 1) * $perPage) + $rows->count(),
        ];
    }

    /**
     * @return array{amount_lkr: ?float, quantity: ?float, state: string}
     */
    private function frozenTrendTotals(
        Collection $rows,
        string $metric,
        ?string $collectionCohort,
        ?string $commissionCategory,
    ): array
    {
        if ($rows->isEmpty()) return ['amount_lkr' => 0.0, 'quantity' => $metric === 'new_sales' ? 0.0 : null, 'state' => 'complete'];
        $snapshots = $rows->map(fn ($row) => is_string($row->metric_snapshot)
            ? (json_decode($row->metric_snapshot, true) ?: []) : ((array) $row->metric_snapshot));

        if ($collectionCohort !== null) {
            $stateField = $metric === 'commission' ? 'commission_cohort_state' : 'collection_cohort_state';
            $amountField = match ([$metric, $collectionCohort]) {
                ['eligible_collections', 'current_period_booking'] => 'new_booking_collections_lkr',
                ['eligible_collections', 'prior_period_booking'] => 'existing_booking_collections_lkr',
                ['commission', 'current_period_booking'] => 'commission_current_period_booking_lkr',
                ['commission', 'prior_period_booking'] => 'commission_prior_period_booking_lkr',
            };
            if (! $snapshots->every(fn (array $metrics) => data_get($metrics, $stateField) === 'complete')) {
                return ['amount_lkr' => null, 'quantity' => null, 'state' => 'incomplete'];
            }

            return ['amount_lkr' => (float) $snapshots->sum(
                fn (array $metrics) => (float) data_get($metrics, $amountField, 0)
            ), 'quantity' => null, 'state' => 'complete'];
        }
        if ($commissionCategory !== null) {
            if (! $snapshots->every(fn (array $metrics) => data_get($metrics, 'commission_category_state') === 'complete')) {
                return ['amount_lkr' => null, 'quantity' => null, 'state' => 'incomplete'];
            }
            $amountField = $commissionCategory === 'one_time' ? 'commission_one_time_lkr' : 'commission_long_term_lkr';

            return ['amount_lkr' => (float) $snapshots->sum(
                fn (array $metrics) => (float) data_get($metrics, $amountField, 0)
            ), 'quantity' => null, 'state' => 'complete'];
        }

        if ($metric === 'new_sales') {
            $complete = $snapshots->every(fn (array $metrics) => data_get($metrics, 'new_sales_value_state') === 'complete'
                && array_key_exists('net_new_sales_lkr', $metrics));
            if (! $complete) return ['amount_lkr' => null, 'quantity' => null, 'state' => 'incomplete'];
        }

        $amount = match ($metric) {
            'new_sales' => $snapshots->sum(fn (array $metrics) => (float) data_get($metrics, 'net_new_sales_lkr')),
            'eligible_collections' => $rows->sum(fn ($row) => (float) $row->eligible_collections_lkr),
            'commission' => $snapshots->every(
                fn (array $metrics) => array_key_exists('commission_earned_lkr', $metrics)
            ) ? $snapshots->sum(fn (array $metrics) => (float) data_get($metrics, 'commission_earned_lkr', 0))
                : $rows->sum(fn ($row) => (float) $row->commission_new_business_lkr
                    + (float) $row->commission_existing_business_lkr),
        };

        return ['amount_lkr' => (float) $amount,
            'quantity' => $metric === 'new_sales' ? (float) $rows->sum('new_bookings_count') : null,
            'state' => 'complete'];
    }

    private function frozenAgingTotals(Collection $rows): ?array
    {
        $snapshots = $rows->map(fn ($row) => is_string($row->metric_snapshot)
            ? (json_decode($row->metric_snapshot, true) ?: []) : ((array) $row->metric_snapshot));
        if ($snapshots->isEmpty() || ! $snapshots->every(
            fn (array $metrics) => data_get($metrics, 'aging_snapshot_state') === 'complete'
                && array_key_exists('aging_buckets', $metrics)
        )) return null;
        $missing = (int) $snapshots->sum(fn (array $metrics) => (int) data_get($metrics, 'aging_missing_lkr_count', 0));
        return [
            'aging_snapshot_state' => 'complete',
            'aging_schedule_count' => (int) $snapshots->sum(fn (array $metrics) => (int) data_get($metrics, 'aging_schedule_count', 0)),
            'aging_lkr_state' => $missing === 0 ? 'complete' : 'incomplete',
            'aging_missing_lkr_count' => $missing,
            'aging_outstanding_lkr' => $missing === 0 ? round((float) $snapshots->sum(
                fn (array $metrics) => (float) data_get($metrics, 'aging_outstanding_lkr', 0)), 4) : null,
            'aging_buckets' => collect(SalesFrozenCollectionAgingService::BUCKETS)->mapWithKeys(
                function (string $bucket) use ($snapshots) {
                    $missing = (int) $snapshots->sum(
                        fn (array $metrics) => (int) data_get($metrics, "aging_buckets.{$bucket}.missing_lkr_count", 0));
                    return [$bucket => [
                        'schedule_count' => (int) $snapshots->sum(
                            fn (array $metrics) => (int) data_get($metrics, "aging_buckets.{$bucket}.schedule_count", 0)),
                        'missing_lkr_count' => $missing,
                        'lkr_state' => $missing === 0 ? 'complete' : 'incomplete',
                        'outstanding_lkr' => $missing === 0 ? round((float) $snapshots->sum(
                            fn (array $metrics) => (float) data_get($metrics, "aging_buckets.{$bucket}.outstanding_lkr", 0)), 4) : null,
                    ]];
                }
            )->all(),
            'aging_source_checksum' => hash('sha256', implode('|', $snapshots
                ->pluck('aging_source_checksum')->filter()->sort()->values()->all())),
        ];
    }

    private function dashboardProfileIds(
        Request $request,
        string $companyId,
        ?string $selectedProfileId,
        string $notFoundMessage,
    ): array {
        $authorizedIds = $this->scope->profileIds(
            $request->user(), 'sales.performance.view-all', 'sales.performance.view-team', $companyId,
        );
        if ($authorizedIds === null) {
            $authorizedIds = SalesProfile::query()->where('company_id', $companyId)->pluck('id')->all();
        }
        if ($selectedProfileId !== null) {
            abort_unless(in_array($selectedProfileId, $authorizedIds, true)
                && SalesProfile::query()->whereKey($selectedProfileId)->where('company_id', $companyId)->exists(),
                404, $notFoundMessage);
            $authorizedIds = [$selectedProfileId];
        }
        abort_if($authorizedIds === [], 404, $notFoundMessage);

        return $authorizedIds;
    }
}
