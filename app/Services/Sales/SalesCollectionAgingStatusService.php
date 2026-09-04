<?php

namespace App\Services\Sales;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesCollectionAgingStatusService
{
    /** @return array<string, mixed> */
    public function source(string $companyId, array $profileIds, string $from, string $to): array
    {
        $timezoneName = config('sales.business_timezone');
        abort_unless(is_string($timezoneName) && $timezoneName !== '', 409,
            'An approved Sales business timezone is required for collection aging.');
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (\Throwable) {
            abort(409, 'The configured Sales business timezone is invalid.');
        }

        $asOf = CarbonImmutable::now($timezone);
        $fromDate = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $toDate = CarbonImmutable::parse($to, $timezone)->startOfDay();
        $current = $asOf->betweenIncluded($fromDate, $toDate);
        if (! $current) {
            return $this->historicalUnavailable($asOf, $timezoneName);
        }

        $sourceExpression = 'COALESCE(schedule.source_amount, schedule.amount)';
        $currencyExpression = "COALESCE(schedule.source_currency, 'LKR')";
        $outstandingExpression = "{$sourceExpression} - COALESCE(allocated.net_amount,0)";
        $lkrExpression = "CASE
            WHEN {$currencyExpression} = 'LKR' THEN {$outstandingExpression}
            WHEN schedule.lkr_amount IS NOT NULL AND {$sourceExpression} > 0
                THEN schedule.lkr_amount * ({$outstandingExpression}) / {$sourceExpression}
            ELSE NULL END";
        $allocations = DB::table('booking_payment_schedule_allocations')
            ->selectRaw('booking_payment_schedule_id, SUM(amount) net_amount')
            ->whereNull('deleted_at')->groupBy('booking_payment_schedule_id');

        $rows = DB::table('booking_payment_schedules as schedule')
            ->join('bookings as booking', 'booking.id', '=', 'schedule.booking_id')
            ->join('sales_booking_attributions as attribution', 'attribution.booking_id', '=', 'booking.id')
            ->join('sales_profiles as profile', 'profile.id', '=', 'attribution.collection_sales_profile_id')
            ->join('staff', 'staff.id', '=', 'profile.staff_id')
            ->leftJoinSub($allocations, 'allocated', fn ($join) => $join->on(
                'allocated.booking_payment_schedule_id', '=', 'schedule.id'))
            ->where('attribution.company_id', $companyId)
            ->whereIn('attribution.collection_sales_profile_id', $profileIds)
            ->whereNull('schedule.deleted_at')->whereNull('attribution.deleted_at')
            ->where('schedule.status', '!=', 'superseded')
            ->whereRaw("{$outstandingExpression} > 0")
            ->orderBy('schedule.due_date')->orderBy('schedule.id')
            ->get([
                'schedule.id', 'schedule.booking_id', 'booking.booking_number', 'schedule.due_date',
                'schedule.sequence', 'schedule.label', 'schedule.schedule_kind',
                'schedule.is_collection_target_eligible',
                'attribution.collection_sales_profile_id', 'staff.code as staff_code',
                DB::raw("{$sourceExpression} as scheduled_source_amount"),
                DB::raw('COALESCE(allocated.net_amount,0) as allocated_source_amount'),
                DB::raw("{$currencyExpression} as source_currency"),
                DB::raw("{$outstandingExpression} as outstanding_source_amount"),
                DB::raw("{$lkrExpression} as outstanding_lkr"),
            ])->map(function ($row) use ($asOf) {
                $row->is_collection_target_eligible = (bool) $row->is_collection_target_eligible;
                $days = CarbonImmutable::parse($row->due_date, $asOf->getTimezone())
                    ->startOfDay()->diffInDays($asOf->startOfDay(), false);
                $row->days_overdue = max(0, $days);
                $row->aging_bucket = match (true) {
                    $days < 0 => 'not_due',
                    $days === 0 => 'due_today',
                    $days <= 30 => '1_30',
                    $days <= 60 => '31_60',
                    $days <= 90 => '61_90',
                    default => '91_plus',
                };
                $row->lkr_state = $row->outstanding_lkr === null ? 'missing' : 'complete';

                return $row;
            });

        $overdue = $rows->whereIn('aging_bucket', ['1_30', '31_60', '61_90', '91_plus'])->values();
        $eligible = $rows->filter(fn ($row) => $row->is_collection_target_eligible === true)->values();
        $eligibleOverdue = $overdue->filter(fn ($row) => $row->is_collection_target_eligible === true)->values();

        return [
            'as_of' => $asOf->toIso8601String(),
            'timezone' => $timezoneName,
            'stock_state' => 'current',
            'definition' => 'Outstanding is current non-superseded schedule source amount minus net allocations; overdue means due before the returned business-date as_of.',
            'summary' => [
                'outstanding_schedule_count' => $rows->count(),
                'outstanding_lkr' => $this->completeLkrTotal($rows),
                'outstanding_lkr_state' => $rows->contains('lkr_state', 'missing') ? 'incomplete' : 'complete',
                'outstanding_missing_lkr_count' => $rows->where('lkr_state', 'missing')->count(),
                'overdue_schedule_count' => $overdue->count(),
                'overdue_lkr' => $this->completeLkrTotal($overdue),
                'overdue_lkr_state' => $overdue->contains('lkr_state', 'missing') ? 'incomplete' : 'complete',
                'overdue_missing_lkr_count' => $overdue->where('lkr_state', 'missing')->count(),
                'sales_eligible_outstanding_schedule_count' => $eligible->count(),
                'sales_eligible_outstanding_lkr' => $this->completeLkrTotal($eligible),
                'sales_eligible_outstanding_lkr_state' => $eligible->contains('lkr_state', 'missing') ? 'incomplete' : 'complete',
                'sales_eligible_outstanding_missing_lkr_count' => $eligible->where('lkr_state', 'missing')->count(),
                'sales_eligible_overdue_schedule_count' => $eligibleOverdue->count(),
                'sales_eligible_overdue_lkr' => $this->completeLkrTotal($eligibleOverdue),
                'sales_eligible_overdue_lkr_state' => $eligibleOverdue->contains('lkr_state', 'missing') ? 'incomplete' : 'complete',
                'sales_eligible_overdue_missing_lkr_count' => $eligibleOverdue->where('lkr_state', 'missing')->count(),
                'source_currency_breakdown' => $rows->groupBy('source_currency')->map(fn (Collection $currencyRows) => [
                    'schedule_count' => $currencyRows->count(),
                    'outstanding_source_amount' => round((float) $currencyRows->sum('outstanding_source_amount'), 4),
                ])->all(),
            ],
            'buckets' => collect(['not_due', 'due_today', '1_30', '31_60', '61_90', '91_plus'])
                ->mapWithKeys(function (string $bucket) use ($rows) {
                    $bucketRows = $rows->where('aging_bucket', $bucket)->values();
                    return [$bucket => [
                        'schedule_count' => $bucketRows->count(),
                        'outstanding_lkr' => $this->completeLkrTotal($bucketRows),
                        'lkr_state' => $bucketRows->contains('lkr_state', 'missing') ? 'incomplete' : 'complete',
                        'missing_lkr_count' => $bucketRows->where('lkr_state', 'missing')->count(),
                    ]];
                })->all(),
            'rows' => $rows,
        ];
    }

    private function completeLkrTotal(Collection $rows): ?float
    {
        return $rows->contains('lkr_state', 'missing')
            ? null : round((float) $rows->sum('outstanding_lkr'), 4);
    }

    /** @return array<string, mixed> */
    private function historicalUnavailable(CarbonImmutable $asOf, string $timezone): array
    {
        return [
            'as_of' => $asOf->toIso8601String(), 'timezone' => $timezone,
            'stock_state' => 'historical_reconstruction_unavailable',
            'definition' => 'Historical schedule and allocation state is not reconstructed from current rows.',
            'summary' => [
                'outstanding_schedule_count' => null, 'outstanding_lkr' => null,
                'outstanding_lkr_state' => 'unavailable', 'outstanding_missing_lkr_count' => null,
                'overdue_schedule_count' => null, 'overdue_lkr' => null,
                'overdue_lkr_state' => 'unavailable', 'overdue_missing_lkr_count' => null,
                'sales_eligible_outstanding_schedule_count' => null, 'sales_eligible_outstanding_lkr' => null,
                'sales_eligible_outstanding_lkr_state' => 'unavailable', 'sales_eligible_outstanding_missing_lkr_count' => null,
                'sales_eligible_overdue_schedule_count' => null, 'sales_eligible_overdue_lkr' => null,
                'sales_eligible_overdue_lkr_state' => 'unavailable', 'sales_eligible_overdue_missing_lkr_count' => null,
                'source_currency_breakdown' => [],
            ],
            'buckets' => [], 'rows' => collect(),
        ];
    }
}
