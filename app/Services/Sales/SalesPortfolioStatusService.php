<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Sales\SalesPortfolioStatusPolicyVersion;
use App\Support\Foundation\CanonicalJson;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesPortfolioStatusService
{
    public function __construct(
        private readonly DomainEventPublisher $events,
        private readonly SalesPolicySettingsService $policySettings,
    ) {}

    public function createPolicy(array $data, string $actorUserId): SalesPortfolioStatusPolicyVersion
    {
        $statuses = collect($data['active_booking_statuses'])
            ->map(fn (string $status) => trim($status))->filter()->unique()->sort()->values()->all();
        $payload = [
            'company_id' => $data['company_id'], 'active_booking_statuses' => $statuses,
            'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null,
            'reason' => trim($data['reason']),
        ];
        $checksum = hash('sha256', CanonicalJson::encode($payload));

        return DB::transaction(function () use ($data, $payload, $checksum, $actorUserId) {
            DB::table('companies')->where('id', $payload['company_id'])->lockForUpdate()->firstOrFail();
            $existing = SalesPortfolioStatusPolicyVersion::query()
                ->where('company_id', $payload['company_id'])->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_checksum, $checksum), 409,
                    'The idempotency key was already used with a different portfolio status policy.');
                return $existing;
            }

            $version = (int) SalesPortfolioStatusPolicyVersion::query()
                ->where('company_id', $payload['company_id'])->lockForUpdate()->max('version') + 1;
            $policy = SalesPortfolioStatusPolicyVersion::create($payload + [
                'version' => $version, 'status' => 'draft', 'idempotency_key' => $data['idempotency_key'],
                'request_checksum' => $checksum, 'prepared_by' => $actorUserId,
            ]);
            $this->events->record('sales', $policy->company_id, 'sales_portfolio_status_policy', $policy->id,
                'sales.portfolio_status_policy.drafted', 1, 1,
                ['version' => $policy->version, 'request_checksum' => $checksum], now(), $data['idempotency_key']);

            return $policy;
        });
    }

    public function approvePolicy(
        SalesPortfolioStatusPolicyVersion $policy,
        string $actorUserId,
        string $idempotencyKey,
    ): SalesPortfolioStatusPolicyVersion {
        return DB::transaction(function () use ($policy, $actorUserId, $idempotencyKey) {
            DB::table('companies')->where('id', $policy->company_id)->lockForUpdate()->firstOrFail();
            $locked = SalesPortfolioStatusPolicyVersion::query()->lockForUpdate()->findOrFail($policy->id);
            if ($locked->status === 'approved') {
                abort_unless($locked->approval_idempotency_key === $idempotencyKey, 409,
                    'The policy was approved by a different command.');
                return $locked;
            }
            abort_if($locked->prepared_by === $actorUserId, 403,
                'Portfolio status policy maker and approver must be different users.');
            abort_unless($locked->status === 'draft', 422, 'Only a draft portfolio status policy can be approved.');

            SalesPortfolioStatusPolicyVersion::query()->where('company_id', $locked->company_id)
                ->where('status', 'approved')->whereDate('effective_from', '<=', $locked->effective_until ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $locked->effective_from))
                ->update(['status' => 'superseded']);
            $locked->update([
                'status' => 'approved', 'approved_by' => $actorUserId, 'approved_at' => now(),
                'approval_idempotency_key' => $idempotencyKey,
            ]);
            $this->events->record('sales', $locked->company_id, 'sales_portfolio_status_policy', $locked->id,
                'sales.portfolio_status_policy.approved', 2, 1,
                ['version' => $locked->version, 'request_checksum' => $locked->request_checksum], now(), $idempotencyKey);

            return $locked->refresh();
        });
    }

    public function policyFor(string $companyId, CarbonImmutable $asOf): SalesPortfolioStatusPolicyVersion
    {
        $policies = SalesPortfolioStatusPolicyVersion::query()->where('company_id', $companyId)
            ->where('status', 'approved')->whereDate('effective_from', '<=', $asOf->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', $asOf->toDateString()))
            ->orderByDesc('version')->limit(2)->get();
        abort_if($policies->isEmpty(), 409,
            'CONFIGURATION_MISSING: no approved active-booking status policy covers the portfolio as-of date.');
        abort_if($policies->count() !== 1, 409,
            'RECONCILIATION_BLOCK: more than one approved active-booking status policy covers the portfolio as-of date.');

        return $policies->first();
    }

    public function source(
        string $companyId,
        array $profileIds,
        string $selectedTo,
        int $requestedPage,
        int $perPage,
    ): array {
        $timezoneName = $this->policySettings->businessTimezone($companyId);
        abort_unless(is_string($timezoneName) && $timezoneName !== '', 409,
            'CONFIGURATION_MISSING: an approved Sales business timezone is required for active portfolio reporting.');
        try {
            new DateTimeZone($timezoneName);
        } catch (\Throwable) {
            abort(409, 'CONFIGURATION_MISSING: the configured Sales business timezone is invalid.');
        }
        $asOf = CarbonImmutable::now($timezoneName);
        if (CarbonImmutable::parse($selectedTo, $timezoneName)->lt($asOf->startOfDay())) {
            return [
                'state' => 'historical_reconstruction_required', 'as_of' => null,
                'business_timezone' => $timezoneName, 'policy' => null, 'summary' => null,
                'bookings' => $this->pageEnvelope(collect(), 1, $perPage, 0, 1),
                'stock_definition' => 'Historical active-booking status is withheld until immutable lifecycle reconstruction exists.',
            ];
        }

        $policy = $this->policyFor($companyId, $asOf);
        $statuses = $policy->active_booking_statuses;
        $scheduleTotals = $this->scheduleTotals();
        $base = DB::table('sales_booking_attributions as attribution')
            ->join('bookings as booking', 'booking.id', '=', 'attribution.booking_id')
            ->leftJoin('booking_payment_schedule_rules as rolling_rule', function ($join) {
                $join->on('rolling_rule.booking_id', '=', 'booking.id')
                    ->where('rolling_rule.status', '=', 'active')->whereNull('rolling_rule.deleted_at');
            })
            ->leftJoinSub($scheduleTotals, 'schedule_totals',
                fn ($join) => $join->on('schedule_totals.booking_id', '=', 'booking.id'))
            ->where('attribution.company_id', $companyId)->whereNull('attribution.root_attribution_id')
            ->where('attribution.status', 'active')->whereNull('attribution.deleted_at')->whereNull('booking.deleted_at')
            ->whereIn('booking.status', $statuses)->where('attribution.secured_at', '<=', $asOf->utc())
            ->where(function ($query) use ($profileIds) {
                $query->whereIn('attribution.acquisition_sales_profile_id', $profileIds)
                    ->orWhereIn('attribution.collection_sales_profile_id', $profileIds);
            });

        $summaryRow = (clone $base)->selectRaw(
            'COUNT(*) active_booking_count,
             SUM(CASE WHEN rolling_rule.id IS NULL THEN 1 ELSE 0 END) fixed_term_count,
             SUM(CASE WHEN rolling_rule.id IS NOT NULL THEN 1 ELSE 0 END) open_ended_count,
             SUM(CASE WHEN rolling_rule.id IS NULL AND attribution.contract_value_lkr IS NULL THEN 1 ELSE 0 END) fixed_term_missing_lkr_count,
             SUM(CASE WHEN rolling_rule.id IS NULL THEN attribution.contract_value_lkr ELSE 0 END) fixed_term_contract_value_lkr,
             SUM(CASE WHEN rolling_rule.id IS NOT NULL AND rolling_rule.lkr_amount IS NULL THEN 1 ELSE 0 END) open_ended_missing_lkr_count,
             SUM(CASE WHEN rolling_rule.id IS NOT NULL THEN rolling_rule.lkr_amount ELSE 0 END) open_ended_monthly_run_rate_lkr,
             SUM(CASE WHEN rolling_rule.id IS NOT NULL THEN COALESCE(schedule_totals.generated_lkr,0) ELSE 0 END) open_ended_generated_horizon_lkr,
             SUM(CASE WHEN rolling_rule.id IS NOT NULL THEN COALESCE(schedule_totals.allocated_lkr,0) ELSE 0 END) open_ended_collected_lkr,
             SUM(CASE WHEN rolling_rule.id IS NOT NULL THEN COALESCE(schedule_totals.outstanding_lkr,0) ELSE 0 END) open_ended_generated_outstanding_lkr,
             SUM(CASE WHEN rolling_rule.id IS NOT NULL THEN COALESCE(schedule_totals.missing_lkr_count,0) ELSE 0 END) open_ended_schedule_missing_lkr_count'
        )->first();
        $total = (int) ($summaryRow->active_booking_count ?? 0);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($requestedPage, $lastPage);
        $rows = (clone $base)->orderByDesc('attribution.secured_at')->orderBy('attribution.id')
            ->forPage($page, $perPage)->get([
                'attribution.id', 'attribution.booking_id', 'booking.booking_number', 'booking.status as booking_status',
                'attribution.acquisition_sales_profile_id', 'attribution.collection_sales_profile_id',
                'attribution.commission_category', 'attribution.secured_at', 'attribution.source_currency',
                'attribution.contract_value_source', 'attribution.contract_value_lkr',
                'rolling_rule.id as rolling_rule_id', 'rolling_rule.source_amount as monthly_run_rate_source',
                'rolling_rule.source_currency as monthly_run_rate_currency', 'rolling_rule.lkr_amount as monthly_run_rate_lkr',
                'schedule_totals.horizon_from', 'schedule_totals.horizon_to', 'schedule_totals.generated_lkr',
                'schedule_totals.allocated_lkr', 'schedule_totals.outstanding_lkr', 'schedule_totals.missing_lkr_count',
            ])->map(function ($row) use ($profileIds) {
                $row->contract_type = $row->rolling_rule_id ? 'open_ended' : 'fixed_term';
                $row->won_in_scope = in_array($row->acquisition_sales_profile_id, $profileIds, true);
                $row->managed_in_scope = in_array($row->collection_sales_profile_id, $profileIds, true);
                $row->value_state = $row->contract_type === 'fixed_term'
                    ? ($row->contract_value_lkr === null ? 'missing_lkr' : 'complete')
                    : (($row->monthly_run_rate_lkr === null || (int) $row->missing_lkr_count > 0) ? 'missing_lkr' : 'complete');
                unset($row->rolling_rule_id, $row->acquisition_sales_profile_id, $row->collection_sales_profile_id);
                return $row;
            });
        $fixedMissing = (int) ($summaryRow->fixed_term_missing_lkr_count ?? 0);
        $openMissing = (int) ($summaryRow->open_ended_missing_lkr_count ?? 0)
            + (int) ($summaryRow->open_ended_schedule_missing_lkr_count ?? 0);

        return [
            'state' => 'current', 'as_of' => $asOf->toIso8601String(), 'business_timezone' => $timezoneName,
            'policy' => ['id' => $policy->id, 'version' => $policy->version,
                'effective_from' => $policy->effective_from->toDateString(),
                'effective_until' => $policy->effective_until?->toDateString(),
                'active_booking_statuses' => $statuses, 'request_checksum' => $policy->request_checksum],
            'summary' => [
                'active_booking_count' => $total,
                'fixed_term_count' => (int) ($summaryRow->fixed_term_count ?? 0),
                'fixed_term_value_state' => $fixedMissing === 0 ? 'complete' : 'missing_lkr',
                'fixed_term_missing_lkr_count' => $fixedMissing,
                'fixed_term_contract_value_lkr' => $fixedMissing === 0
                    ? (float) ($summaryRow->fixed_term_contract_value_lkr ?? 0) : null,
                'open_ended_count' => (int) ($summaryRow->open_ended_count ?? 0),
                'open_ended_value_state' => $openMissing === 0 ? 'complete' : 'missing_lkr',
                'open_ended_missing_lkr_count' => $openMissing,
                'open_ended_monthly_run_rate_lkr' => $openMissing === 0
                    ? (float) ($summaryRow->open_ended_monthly_run_rate_lkr ?? 0) : null,
                'open_ended_generated_horizon_lkr' => $openMissing === 0
                    ? (float) ($summaryRow->open_ended_generated_horizon_lkr ?? 0) : null,
                'open_ended_collected_lkr' => $openMissing === 0
                    ? (float) ($summaryRow->open_ended_collected_lkr ?? 0) : null,
                'open_ended_generated_outstanding_lkr' => $openMissing === 0
                    ? (float) ($summaryRow->open_ended_generated_outstanding_lkr ?? 0) : null,
                'lifetime_contract_value_lkr' => (int) ($summaryRow->open_ended_count ?? 0) > 0
                    ? null : ($fixedMissing === 0 ? (float) ($summaryRow->fixed_term_contract_value_lkr ?? 0) : null),
                'lifetime_value_state' => (int) ($summaryRow->open_ended_count ?? 0) > 0
                    ? 'not_applicable_open_ended' : ($fixedMissing === 0 ? 'complete' : 'missing_lkr'),
            ],
            'bookings' => $this->pageEnvelope($rows, $page, $perPage, $total, $lastPage),
            'stock_definition' => 'Root bookings in an approved active status at the current as-of instant; never summed across periods.',
            'canonical_record_access' => 'Opening Booking Management requires bookings.view in addition to scoped Sales performance access.',
        ];
    }

    private function scheduleTotals(): Builder
    {
        $allocated = DB::table('booking_payment_schedule_allocations')->selectRaw(
            'booking_payment_schedule_id, SUM(amount) allocated_source'
        )->whereNull('deleted_at')->groupBy('booking_payment_schedule_id');
        $source = 'COALESCE(schedule.source_amount, schedule.amount)';
        $currency = "COALESCE(schedule.source_currency, 'LKR')";
        $lkr = "CASE WHEN {$currency} = 'LKR' THEN {$source} ELSE schedule.lkr_amount END";
        $allocatedLkr = "CASE WHEN {$currency} = 'LKR' THEN COALESCE(allocation.allocated_source,0)
            WHEN schedule.lkr_amount IS NOT NULL AND {$source} > 0
            THEN schedule.lkr_amount * COALESCE(allocation.allocated_source,0) / {$source} ELSE NULL END";

        return DB::table('booking_payment_schedules as schedule')
            ->leftJoinSub($allocated, 'allocation',
                fn ($join) => $join->on('allocation.booking_payment_schedule_id', '=', 'schedule.id'))
            ->whereNull('schedule.deleted_at')->where('schedule.status', '!=', 'superseded')
            ->selectRaw("schedule.booking_id, MIN(schedule.due_date) horizon_from, MAX(schedule.due_date) horizon_to,
                SUM(CASE WHEN {$lkr} IS NULL THEN 1 ELSE 0 END) missing_lkr_count,
                SUM(COALESCE({$lkr},0)) generated_lkr,
                SUM(COALESCE({$allocatedLkr},0)) allocated_lkr,
                SUM(COALESCE({$lkr},0) - COALESCE({$allocatedLkr},0)) outstanding_lkr")
            ->groupBy('schedule.booking_id');
    }

    private function pageEnvelope(Collection $rows, int $page, int $perPage, int $total, int $lastPage): array
    {
        return ['data' => $rows->values(), 'current_page' => $page, 'per_page' => $perPage,
            'last_page' => $lastPage, 'total' => $total,
            'from' => $rows->isEmpty() ? null : (($page - 1) * $perPage) + 1,
            'to' => $rows->isEmpty() ? null : (($page - 1) * $perPage) + $rows->count()];
    }
}
