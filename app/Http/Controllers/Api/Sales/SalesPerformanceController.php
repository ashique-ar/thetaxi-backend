<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesAlertPolicyVersion;
use App\Models\Sales\SalesKpiSnapshot;
use App\Models\Sales\SalesPerformanceAlert;
use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesTargetVersion;
use App\Services\Sales\SalesAccessScope;
use App\Services\Sales\SalesAlertReconciliationService;
use App\Services\Sales\SalesPeriodCloseService;
use App\Services\Sales\SalesPerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SalesPerformanceController extends Controller
{
    public function __construct(private readonly SalesAccessScope $scope) {}

    public function targets(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'sales_profile_id' => ['nullable', 'uuid'],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', Rule::in(['draft', 'approved', 'superseded'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $ids = $this->scope->profileIds(
            $request->user(), 'sales.performance.view-all', 'sales.performance.view-team', $data['company_id'],
        );
        $query = SalesTargetVersion::query()
            ->leftJoin('sales_profiles as target_profile', 'target_profile.id', '=', 'sales_target_versions.sales_profile_id')
            ->leftJoin('staff as target_staff', 'target_staff.id', '=', 'target_profile.staff_id')
            ->select(['sales_target_versions.*', 'target_profile.sales_code as profile_sales_code',
                'target_staff.code as staff_code'])
            ->when($ids !== null, fn ($target) => $target->whereIn('sales_target_versions.sales_profile_id', $ids))
            ->where('sales_target_versions.company_id', $data['company_id'])
            ->when($data['sales_profile_id'] ?? null, fn ($target, string $profileId) => $target->where('sales_target_versions.sales_profile_id', $profileId))
            ->when($data['month'] ?? null, fn ($target, string $month) => $target->whereDate('sales_target_versions.period_start', $month.'-01'))
            ->when($data['status'] ?? null, fn ($target, string $status) => $target->where('sales_target_versions.status', $status))
            ->orderByDesc('sales_target_versions.period_start')->orderBy('sales_target_versions.sales_profile_id')
            ->orderByDesc('sales_target_versions.version')->orderBy('sales_target_versions.id');
        $perPage = (int) ($data['per_page'] ?? 25);
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min((int) ($data['page'] ?? 1), $lastPage);
        $rows = $query->forPage($page, $perPage)->get();
        return response()->json(['status' => 'success', 'data' => [
            'data' => $rows, 'current_page' => $page, 'per_page' => $perPage,
            'last_page' => $lastPage, 'total' => $total,
            'from' => $rows->isEmpty() ? null : (($page - 1) * $perPage) + 1,
            'to' => $rows->isEmpty() ? null : (($page - 1) * $perPage) + $rows->count(),
        ]]);
    }

    public function administrationContext(Request $request): JsonResponse
    {
        $ids = $this->scope->profileIds($request->user(), 'sales.performance.view-all', 'sales.performance.view-team');
        $profiles = SalesProfile::query()->with('staff:id,user_id,code,staff_type')->where('status', 'active')->activeAt(now())
            ->whereHas('staff', fn ($query) => $query->where(fn ($active) => $active->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())))
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('sales_code')->get(['id', 'company_id', 'staff_id', 'sales_code']);
        $companyIds = $profiles->pluck('company_id')->unique()->values();
        $companies = DB::table('companies')->whereIn('id', $companyIds)->orderBy('name')->get(['id', 'name']);
        $policies = SalesAlertPolicyVersion::query()->whereIn('company_id', $companyIds)->orderByDesc('effective_from')->orderByDesc('version')->get();
        $alertOwners = $profiles->filter(fn ($profile) => $profile->staff?->user_id)->map(fn ($profile) => [
            'user_id' => $profile->staff->user_id, 'company_id' => $profile->company_id,
            'label' => $profile->sales_code.' · '.$profile->staff->code,
        ])->unique('user_id')->values();
        return response()->json(['status' => 'success', 'data' => compact('companies', 'profiles', 'policies') + [
            'alert_owners' => $alertOwners,
        ]]);
    }

    public function createTarget(Request $request, SalesPerformanceService $performance): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'uuid', 'exists:companies,id'], 'sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'new_sales_target_lkr' => ['nullable', 'numeric', 'min:0'], 'eligible_collections_target_lkr' => ['nullable', 'numeric', 'min:0'], 'reason' => ['required', 'string', 'min:10', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $profile = SalesProfile::query()->findOrFail($data['sales_profile_id']);
        $this->scope->assertProfile($request->user(), $profile, 'sales.performance.view-all', 'sales.performance.view-team');
        return response()->json(['status' => 'success', 'data' => $performance->createTarget(
            $data, $data['idempotency_key'], (string) $request->user()->id, $request->ip(),
        )], 201);
    }

    public function previewTargetCopy(Request $request, SalesPerformanceService $performance): JsonResponse
    {
        $data = $this->targetCopyInput($request);
        $this->assertTargetProfilesScope($request, $data['company_id'], $data['profile_ids']);

        return response()->json(['status' => 'success', 'data' => $performance->previewTargetCopy(
            $data['company_id'], $data['source_period_start'], $data['target_period_start'], $data['profile_ids'],
        )]);
    }

    public function copyTargets(Request $request, SalesPerformanceService $performance): JsonResponse
    {
        $data = $this->targetCopyInput($request, true);
        $this->assertTargetProfilesScope($request, $data['company_id'], $data['profile_ids']);
        $batch = $performance->copyTargets(
            $data['company_id'], $data['source_period_start'], $data['target_period_start'], $data['profile_ids'],
            $data['preview_checksum'], $data['reason'], $data['idempotency_key'], (string) $request->user()->id,
        );

        return response()->json(['status' => 'success', 'data' => $batch], 201);
    }

    public function previewTargetImport(Request $request, SalesPerformanceService $performance): JsonResponse
    {
        $data = $this->targetImportInput($request);
        $this->assertCompanyScope($request, $data['company_id']);
        $profileIds = $this->scope->profileIds(
            $request->user(), 'sales.performance.view-all', 'sales.performance.view-team'
        );

        return response()->json(['status' => 'success', 'data' => $performance->previewTargetImport(
            $data['company_id'], $data['target_period_start'], $data['file'], $profileIds,
        )]);
    }

    public function importTargets(Request $request, SalesPerformanceService $performance): JsonResponse
    {
        $data = $this->targetImportInput($request, true);
        $this->assertCompanyScope($request, $data['company_id']);
        $profileIds = $this->scope->profileIds(
            $request->user(), 'sales.performance.view-all', 'sales.performance.view-team'
        );
        $job = $performance->importTargets(
            $data['company_id'], $data['target_period_start'], $data['file'], $profileIds,
            $data['preview_checksum'], $data['reason'], $data['idempotency_key'], (string) $request->user()->id,
        );

        return response()->json(['status' => 'success', 'data' => $job], 201);
    }

    public function approveTarget(Request $request, SalesTargetVersion $target, SalesPerformanceService $performance): JsonResponse
    {
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $profile = SalesProfile::query()->findOrFail($target->sales_profile_id);
        $this->scope->assertProfile($request->user(), $profile, 'sales.performance.view-all', 'sales.performance.view-team');
        return response()->json(['status' => 'success', 'data' => $performance->approveTarget(
            $target, $data['expected_version'], $data['reason'], $data['idempotency_key'], (string) $request->user()->id,
            $request->ip(),
        )]);
    }

    public function preview(Request $request, SalesPerformanceService $performance): JsonResponse
    {
        $data = $this->periodInput($request);
        $result = $performance->preview($data['company_id'], $data['period_start'], $data['period_end'], $data['cutoff_at']);
        $ids = $this->scope->profileIds($request->user(), 'sales.performance.view-all', 'sales.performance.view-team');
        if ($ids !== null) $result['rows'] = array_values(array_filter($result['rows'], fn ($row) => in_array($row['sales_profile_id'], $ids, true)));
        return response()->json(['status' => 'success', 'data' => $result]);
    }

    public function freeze(Request $request, SalesPerformanceService $performance): JsonResponse
    {
        $data = $this->periodInput($request, true);
        $this->assertCompanyWideScope($request, $data['company_id']);
        return response()->json(['status' => 'success', 'data' => $performance->freeze($data['company_id'], $data['period_start'], $data['period_end'], $data['period_type'], $data['cutoff_at'], $data['idempotency_key'], (string) $request->user()->id)], 201);
    }

    public function previewPeriodClose(Request $request, SalesPeriodCloseService $periodClose): JsonResponse
    {
        $data = $this->periodCloseInput($request);
        $this->assertCompanyWideScope($request, $data['company_id']);
        return response()->json(['status' => 'success', 'data' => $periodClose->preview(
            $data['company_id'], $data['period_start'], $data['cutoff_at'],
        )]);
    }

    public function closePeriod(Request $request, SalesPeriodCloseService $periodClose): JsonResponse
    {
        $data = $this->periodCloseInput($request, true);
        $this->assertCompanyWideScope($request, $data['company_id']);
        return response()->json(['status' => 'success', 'data' => $periodClose->close(
            $data['company_id'], $data['period_start'], $data['cutoff_at'], $data['expected_lock_version'],
            $data['preview_checksum'], $data['reason'], $data['idempotency_key'], (string) $request->user()->id,
        )], 201);
    }

    public function periodLocks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->assertCompanyWideScope($request, $data['company_id']);
        $query = DB::table('domain_period_locks as period_lock')
            ->leftJoin('sales_kpi_snapshots as snapshot', function ($join) {
                $join->on('snapshot.period_lock_id', '=', 'period_lock.id')->where('snapshot.status', '=', 'frozen');
            })
            ->where('period_lock.domain', 'sales')->where('period_lock.company_id', $data['company_id'])
            ->select(['period_lock.id', 'period_lock.company_id', 'period_lock.period_type', 'period_lock.period_start',
                'period_lock.period_end', 'period_lock.state', 'period_lock.lock_version', 'period_lock.reason',
                'period_lock.locked_at', 'period_lock.reopened_at', 'snapshot.id as current_snapshot_id',
                'snapshot.snapshot_checksum as current_snapshot_checksum']);
        return response()->json(['status' => 'success', 'data' => $query->orderByDesc('period_lock.period_end')
            ->paginate($request->integer('per_page', 12))]);
    }

    public function reopenPeriod(Request $request, string $periodLock, SalesPeriodCloseService $periodClose): JsonResponse
    {
        $lock = DB::table('domain_period_locks')->whereKey($periodLock)->where('domain', 'sales')->first();
        abort_unless($lock, 404);
        $this->assertCompanyWideScope($request, $lock->company_id);
        $data = $request->validate([
            'expected_lock_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        return response()->json(['status' => 'success', 'data' => $periodClose->reopen(
            $periodLock, $data['expected_lock_version'], $data['reason'], $data['idempotency_key'],
            (string) $request->user()->id,
        )]);
    }

    public function snapshots(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $ids = $this->scope->profileIds($request->user(), 'sales.performance.view-all', 'sales.performance.view-team');
        $query = SalesKpiSnapshot::query()->with(['rows' => fn ($q) => $ids === null ? $q : $q->whereIn('sales_profile_id', $ids)]);
        if ($ids !== null) {
            $companyIds = SalesProfile::query()->withTrashed()->whereIn('id', $ids)->pluck('company_id')->unique();
            $query->whereIn('company_id', $companyIds);
        }
        $page = $query->when($data['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->latest('period_end')->paginate($request->integer('per_page', 12));
        if ($ids !== null) {
            $page->getCollection()->each(fn (SalesKpiSnapshot $snapshot) => $snapshot->makeHidden([
                'snapshot_checksum', 'generation_payload_checksum', 'generation_idempotency_key',
                'source_reconciliation_snapshot', 'source_reconciliation_checksum',
            ]));
        }
        return response()->json(['status' => 'success', 'data' => $page]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'acknowledged', 'resolved'])],
            'include_snoozed' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $ids = $this->scope->profileIds($request->user(), 'sales.performance.view-all', 'sales.performance.view-team');
        $query = SalesPerformanceAlert::query()->whereIn('snapshot_id', SalesKpiSnapshot::query()
            ->where('status', 'frozen')->select('id'));
        if ($ids !== null) $query->whereIn('sales_profile_id', $ids);
        $query->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        if (config('sales.features.performance_alert_actions', false) && ! ($data['include_snoozed'] ?? false)) {
            $query->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
        }
        return response()->json(['status' => 'success', 'data' => $query->orderByRaw("CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")->latest('detected_at')->paginate($request->integer('per_page', 25))]);
    }

    public function reconcileAlert(
        Request $request,
        SalesPerformanceAlert $alert,
        SalesAlertReconciliationService $reconciliation,
    ): JsonResponse {
        $profile = SalesProfile::query()->findOrFail($alert->sales_profile_id);
        $this->scope->assertProfile($request->user(), $profile, 'sales.performance.view-all', 'sales.performance.view-team');
        $ids = $this->scope->profileIds(
            $request->user(), 'sales.performance.view-all', 'sales.performance.view-team', $profile->company_id,
        );

        return response()->json([
            'status' => 'success',
            'data' => $reconciliation->reconcile($alert, $ids === null),
        ]);
    }

    public function transitionAlert(Request $request, SalesPerformanceAlert $alert, SalesPerformanceService $performance): JsonResponse
    {
        $profile = SalesProfile::query()->findOrFail($alert->sales_profile_id);
        $this->scope->assertProfile($request->user(), $profile, 'sales.performance.view-all', 'sales.performance.view-team');
        $data = $request->validate([
            'to_status' => ['required', Rule::in(['open', 'acknowledged', 'resolved'])],
            'expected_version' => ['required', 'integer', 'min:1'], 'note' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $action = ['open' => 'reopen', 'acknowledged' => 'acknowledge', 'resolved' => 'resolve'][$data['to_status']];
        return response()->json(['status' => 'success', 'data' => $performance->actOnAlert(
            $alert, $action, $data['expected_version'], $data['note'], $data['idempotency_key'], (string) $request->user()->id,
        )]);
    }

    public function actOnAlert(Request $request, SalesPerformanceAlert $alert, SalesPerformanceService $performance): JsonResponse
    {
        $profile = SalesProfile::query()->findOrFail($alert->sales_profile_id);
        $this->scope->assertProfile($request->user(), $profile, 'sales.performance.view-all', 'sales.performance.view-team');
        $data = $request->validate([
            'action' => ['required', Rule::in(['acknowledge', 'resolve', 'reopen', 'snooze'])],
            'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'],
            'snoozed_until' => ['nullable', 'required_if:action,snooze', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        return response()->json(['status' => 'success', 'data' => $performance->actOnAlert(
            $alert, $data['action'], $data['expected_version'], $data['reason'], $data['idempotency_key'],
            (string) $request->user()->id, $data['snoozed_until'] ?? null,
        )]);
    }

    public function escalateAlert(Request $request, SalesPerformanceAlert $alert, SalesPerformanceService $performance): JsonResponse
    {
        $profile = SalesProfile::query()->findOrFail($alert->sales_profile_id);
        $this->scope->assertProfile($request->user(), $profile, 'sales.performance.view-all', 'sales.performance.view-team');
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        return response()->json(['status' => 'success', 'data' => $performance->actOnAlert(
            $alert, 'escalate', $data['expected_version'], $data['reason'], $data['idempotency_key'], (string) $request->user()->id,
        )]);
    }

    public function createAlertPolicy(Request $request, SalesPerformanceService $performance): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'rules' => ['required', 'array'],
            'rules.no_new_sales.enabled' => ['required', 'boolean'], 'rules.no_new_sales.severity' => ['required', Rule::in(['low', 'medium', 'high'])],
            'rules.no_sales_activity.enabled' => ['required', 'boolean'], 'rules.no_sales_activity.severity' => ['required', Rule::in(['low', 'medium', 'high'])],
            'rules.overdue_collections.enabled' => ['required', 'boolean'], 'rules.overdue_collections.severity' => ['required', Rule::in(['low', 'medium', 'high'])],
            'rules.overdue_collections.minimum_amount_lkr' => ['required', 'numeric', 'min:0'],
            'rules.overdue_collections.minimum_age_days' => ['required', 'integer', 'min:1', 'max:3660'],
            'rules.overdue_tasks.enabled' => ['required', 'boolean'],
            'rules.overdue_tasks.severity' => ['required', Rule::in(['low', 'medium', 'high'])],
            'rules.overdue_tasks.minimum_count' => ['required', 'integer', 'min:1', 'max:10000'],
            'rules.overdue_tasks.minimum_age_days' => ['required', 'integer', 'min:1', 'max:3660'],
            'rules.overdue_tasks.status_basis' => ['required', Rule::in(['open_or_in_progress_at_period_end'])],
            'rules.overdue_tasks.owner_basis' => ['required', Rule::in(['owner_at_period_end'])],
            'rules.repeatedly_missed_next_actions.enabled' => ['required', 'boolean'],
            'rules.repeatedly_missed_next_actions.severity' => ['required', Rule::in(['low', 'medium', 'high'])],
            'rules.repeatedly_missed_next_actions.minimum_count' => ['required', 'integer', 'min:2', 'max:10000'],
            'rules.repeatedly_missed_next_actions.lookback_completed_months' => ['required', 'integer', 'min:1', 'max:12'],
            'rules.repeatedly_missed_next_actions.deadline_basis' => ['required', Rule::in(['open_or_in_progress_at_due'])],
            'rules.repeatedly_missed_next_actions.owner_basis' => ['required', Rule::in(['owner_at_due'])],
            'rules.repeatedly_missed_next_actions.source' => ['required', Rule::in(['governed_sales_task_event_history'])],
            'rules.recurring_commission_reliance.enabled' => ['required', 'boolean'],
            'rules.recurring_commission_reliance.severity' => ['required', Rule::in(['low', 'medium', 'high'])],
            'rules.recurring_commission_reliance.prior_booking_commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'rules.recurring_commission_reliance.new_sales_achievement_below_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'rules.decline_against_completed_month_average.enabled' => ['required', 'boolean'],
            'rules.decline_against_completed_month_average.severity' => ['required', Rule::in(['low', 'medium', 'high'])],
            'rules.decline_against_completed_month_average.percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'rules.decline_against_completed_month_average.baseline_months' => ['required', 'integer', 'min:1', 'max:12'],
            'rules.evaluation.grain' => ['required', Rule::in(['closed_calendar_month'])],
            'rules.evaluation.schedule.frequency' => ['required', Rule::in(['once_after_close'])],
            'rules.evaluation.schedule.local_time' => ['required', 'date_format:H:i'],
            'rules.evaluation.grace_days' => ['required', 'integer', 'min:0', 'max:31'],
            'rules.evaluation.minimum_elapsed_days' => ['required', 'integer', 'min:1', 'max:31'],
            'rules.evaluation.baseline_completeness' => ['required', Rule::in(['require_complete', 'suppress_rule_and_flag'])],
            'rules.evaluation.missing_data_behavior' => ['required', Rule::in(['suppress_rule_and_flag'])],
            'rules.evaluation.comparison_normalization' => ['required', Rule::in(['completed_calendar_months_only'])],
            'rules.evaluation.recurring_reliance_basis' => ['required', Rule::in(['prior_period_booking_commission'])],
            'rules.evaluation.timezone_source' => ['required', Rule::in(['sales_business_timezone'])],
            'rules.evaluation.owner_user_id' => ['required', 'uuid', 'exists:users,id'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $this->assertCompanyWideScope($request, $data['company_id']);
        return response()->json(['status' => 'success', 'data' => $performance->storeAlertPolicy($data, (string) $request->user()->id)], 201);
    }

    public function approveAlertPolicy(Request $request, SalesAlertPolicyVersion $policy, SalesPerformanceService $performance): JsonResponse
    {
        $this->assertCompanyWideScope($request, $policy->company_id);
        return response()->json(['status' => 'success', 'data' => $performance->approveAlertPolicy($policy, (string) $request->user()->id)]);
    }

    private function assertCompanyScope(Request $request, string $companyId): void
    {
        $ids = $this->scope->profileIds($request->user(), 'sales.performance.view-all', 'sales.performance.view-team');
        if ($ids === null) return;
        abort_unless(SalesProfile::query()->whereIn('id', $ids)->where('company_id', $companyId)->exists(), 403, 'Sales performance administration is outside your legal entity or team scope.');
    }

    private function assertCompanyWideScope(Request $request, string $companyId): void
    {
        $ids = $this->scope->profileIds($request->user(), 'sales.performance.view-all', 'sales.performance.view-team');
        abort_unless($ids === null, 403, 'Company-wide Sales reconciliation requires view-all scope.');
        abort_unless(SalesProfile::query()->withTrashed()->where('company_id', $companyId)->exists(), 422,
            'The legal entity has no Sales Profiles to reconcile.');
    }

    private function assertTargetProfilesScope(Request $request, string $companyId, array $profileIds): void
    {
        $profiles = SalesProfile::query()->where('company_id', $companyId)->whereIn('id', $profileIds)->get();
        abort_unless($profiles->count() === count(array_unique($profileIds)), 422,
            'Every selected Sales Profile must belong to the selected legal entity.');
        foreach ($profiles as $profile) {
            $this->scope->assertProfile($request->user(), $profile,
                'sales.performance.view-all', 'sales.performance.view-team');
        }
    }

    private function targetCopyInput(Request $request, bool $commit = false): array
    {
        return $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'source_period_start' => ['required', 'date_format:Y-m-d'],
            'target_period_start' => ['required', 'date_format:Y-m-d', 'different:source_period_start'],
            'profile_ids' => ['required', 'array', 'min:1', 'max:100'],
            'profile_ids.*' => ['required', 'uuid', 'distinct', 'exists:sales_profiles,id'],
            'preview_checksum' => [$commit ? 'required' : 'nullable', 'string', 'size:64'],
            'reason' => [$commit ? 'required' : 'nullable', 'string', 'min:10', 'max:2000'],
            'idempotency_key' => [$commit ? 'required' : 'nullable', 'string', 'max:160'],
        ]);
    }

    private function targetImportInput(Request $request, bool $commit = false): array
    {
        return $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'target_period_start' => ['required', 'date_format:Y-m-d'],
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
            'preview_checksum' => [$commit ? 'required' : 'nullable', 'string', 'size:64'],
            'reason' => [$commit ? 'required' : 'nullable', 'string', 'min:10', 'max:2000'],
            'idempotency_key' => [$commit ? 'required' : 'nullable', 'string', 'max:160'],
        ]);
    }

    private function periodInput(Request $request, bool $requireKey = false): array
    {
        return $request->validate(['company_id' => ['required', 'uuid', 'exists:companies,id'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'period_type' => ['required', Rule::in(['month', 'quarter', 'year', 'custom'])], 'cutoff_at' => ['required', 'date', 'before_or_equal:now'], 'idempotency_key' => [$requireKey ? 'required' : 'nullable', 'string', 'max:160']]);
    }

    private function periodCloseInput(Request $request, bool $commit = false): array
    {
        return $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'cutoff_at' => ['required', 'date', 'before_or_equal:now',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/'],
            'expected_lock_version' => [$commit ? 'required' : 'nullable', 'integer', 'min:0'],
            'preview_checksum' => [$commit ? 'required' : 'nullable', 'string', 'size:64'],
            'reason' => [$commit ? 'required' : 'nullable', 'string', 'min:10', 'max:2000'],
            'idempotency_key' => [$commit ? 'required' : 'nullable', 'string', 'max:160'],
        ]);
    }
}
