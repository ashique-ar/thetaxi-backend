<?php

namespace App\Services\Sales;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesCommissionStatusService
{
    /**
     * Current pending/held/approved balances are stocks. Paid commission is a
     * signed period flow. Historical stocks require event reconstruction and
     * are deliberately unavailable until that source is implemented.
     */
    public function source(string $companyId, array $profileIds, string $from, string $to): array
    {
        $timezone = config('sales.business_timezone');
        abort_unless(is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true),
            409, 'An approved Sales business timezone is required for commission status reporting.');
        $today = CarbonImmutable::now($timezone)->toDateString();
        $stockAvailable = $from <= $today && $to >= $today;
        $fromUtc = CarbonImmutable::parse($from, $timezone)->startOfDay()->utc();
        $toExclusiveUtc = CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc();

        $pending = collect();
        $approved = collect();
        $held = collect();
        if ($stockAvailable) {
            $statementRows = DB::table('sales_commission_statements as statement')
                ->join('staff', 'staff.id', '=', 'statement.staff_id')
                ->where('statement.company_id', $companyId)->whereIn('statement.sales_profile_id', $profileIds)
                ->whereIn('statement.status', ['draft', 'pending_approval', 'approved', 'partially_paid'])
                ->orderByDesc('statement.period_end')->orderBy('statement.id')->get([
                    'statement.id', 'statement.sales_profile_id', 'staff.code as staff_code', 'statement.statement_number',
                    'statement.status', 'statement.period_start', 'statement.period_end', 'statement.net_payable_lkr',
                    'statement.contested_hold_lkr', 'statement.paid_lkr', 'statement.state_version',
                ])->map(function ($row) {
                    $row->remaining_uncontested_lkr = max(0, round((float) $row->net_payable_lkr
                        - (float) $row->contested_hold_lkr - (float) $row->paid_lkr, 4));
                    return $row;
                });
            $pending = $statementRows->whereIn('status', ['draft', 'pending_approval'])->values();
            $approved = $statementRows->whereIn('status', ['approved', 'partially_paid'])->values();
            $held = DB::table('sales_commission_decisions as decision')
                ->join('staff', 'staff.id', '=', 'decision.beneficiary_staff_id')
                ->leftJoin('sales_commission_hold_releases as release', 'release.commission_decision_id', '=', 'decision.id')
                ->leftJoin('sales_commission_hold_adjustments as adjustment', 'adjustment.commission_decision_id', '=', 'decision.id')
                ->leftJoin('sales_commission_hold_resolutions as resolution', 'resolution.commission_decision_id', '=', 'decision.id')
                ->where('decision.company_id', $companyId)->whereIn('decision.beneficiary_sales_profile_id', $profileIds)
                ->whereIn('decision.status', ['held', 'shadow_held'])
                ->whereNull('release.id')->whereNull('adjustment.id')->whereNull('resolution.id')
                ->orderByDesc('decision.decision_at')->orderBy('decision.id')->get([
                    'decision.id', 'decision.beneficiary_sales_profile_id as sales_profile_id', 'staff.code as staff_code',
                    'decision.booking_id', 'decision.receipt_id', 'decision.receipt_component_id', 'decision.status',
                    'decision.hold_code', 'decision.eligible_lkr_amount', 'decision.commission_amount_lkr',
                    'decision.decision_at', 'decision.event_version',
                ]);
        }

        $paid = DB::table('sales_commission_payout_allocations as allocation')
            ->join('sales_commission_payouts as payout', 'payout.id', '=', 'allocation.payout_id')
            ->join('sales_commission_statements as statement', 'statement.id', '=', 'allocation.statement_id')
            ->join('staff', 'staff.id', '=', 'statement.staff_id')
            ->where('payout.company_id', $companyId)->whereIn('statement.sales_profile_id', $profileIds)
            ->where('payout.status', 'confirmed')->where('payout.paid_at', '>=', $fromUtc)
            ->where('payout.paid_at', '<', $toExclusiveUtc)
            ->orderByDesc('payout.paid_at')->orderBy('allocation.id')->get([
                'allocation.id', 'allocation.amount_lkr', 'allocation.statement_id', 'statement.sales_profile_id',
                'staff.code as staff_code', 'statement.statement_number', 'payout.id as payout_id',
                'payout.payout_number', 'payout.paid_at', 'payout.accounting_status',
            ]);

        $heldMissingAmount = $held->whereNull('commission_amount_lkr')->count();
        return [
            'as_of' => CarbonImmutable::now($timezone)->toIso8601String(),
            'stock_state' => $stockAvailable ? 'current' : 'historical_reconstruction_unavailable',
            'definitions' => [
                'pending' => 'Current uncontested remainder on draft or pending-approval statements.',
                'held' => 'Current unresolved held decisions; eligible basis is not commission payable.',
                'approved' => 'Current uncontested approved or partially-paid statement remainder.',
                'paid' => 'Confirmed payout allocations executed inside the selected business-date range; reversed payouts excluded.',
            ],
            'summary' => [
                'pending_commission_lkr' => $stockAvailable ? round((float) $pending->sum('remaining_uncontested_lkr'), 4) : null,
                'pending_statement_count' => $stockAvailable ? $pending->count() : null,
                'held_commission_lkr' => ! $stockAvailable || $heldMissingAmount > 0
                    ? null : round((float) $held->sum('commission_amount_lkr'), 4),
                'held_amount_state' => ! $stockAvailable ? 'unavailable' : ($heldMissingAmount > 0 ? 'incomplete' : 'complete'),
                'held_decision_count' => $stockAvailable ? $held->count() : null,
                'held_amount_missing_count' => $stockAvailable ? $heldMissingAmount : null,
                'held_eligible_basis_lkr' => $stockAvailable ? round((float) $held->sum('eligible_lkr_amount'), 4) : null,
                'approved_unpaid_commission_lkr' => $stockAvailable ? round((float) $approved->sum('remaining_uncontested_lkr'), 4) : null,
                'approved_statement_count' => $stockAvailable ? $approved->count() : null,
                'paid_commission_lkr' => round((float) $paid->sum('amount_lkr'), 4),
                'paid_allocation_count' => $paid->count(),
            ],
            'rows' => compact('pending', 'held', 'approved', 'paid'),
        ];
    }
}
