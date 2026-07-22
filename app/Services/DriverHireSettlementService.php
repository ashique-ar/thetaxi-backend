<?php

namespace App\Services;

use App\Models\Driver\DriverBattaRule;
use App\Models\Driver\DriverHireSettlement;
use Illuminate\Support\Carbon;
use App\Models\Booking\BookingPaymentReceipt;

class DriverHireSettlementService
{
    /**
     * Refresh non-terminal driver balances after physical cash custody changes.
     * The booking receipt remains the payment/revenue evidence; this projection
     * only nets cash still held by the driver against driver compensation.
     */
    public function reconcileCashHandoff(string $driverId, iterable $bookingIds): void
    {
        DriverHireSettlement::query()
            ->where('driver_id', $driverId)
            ->whereIn('booking_id', collect($bookingIds)->filter()->unique()->values())
            ->whereNotIn('status', ['paid', 'recovered', 'rejected'])
            ->get()
            ->each(function (DriverHireSettlement $settlement): void {
                $previousStatus = $settlement->status;
                $settlement = $this->recalculate($settlement);

                if (in_array($previousStatus, ['accounts_finalized', 'recovery_pending'], true)) {
                    $settlement->update([
                        'status' => (float) $settlement->final_balance >= 0
                            ? 'accounts_finalized'
                            : 'recovery_pending',
                    ]);
                }
            });
    }

    public function applyBattaRule(DriverHireSettlement $settlement, ?string $category = null): DriverHireSettlement
    {
        $category = $category ?: $settlement->batta_category;
        if (!$category) {
            return $this->recalculate($settlement);
        }

        $rule = DriverBattaRule::query()
            ->where('vehicle_group_id', $settlement->vehicle_group_id)
            ->where('batta_category', $category)
            ->where('is_active', true)
            ->where(function ($query) {
                $today = now()->toDateString();
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', $today);
            })
            ->where(function ($query) {
                $today = now()->toDateString();
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
            })
            ->latest('effective_from')
            ->first();

        if ($rule) {
            $settlement->fill([
                'batta_rule_id' => $rule->id,
                'batta_category' => $category,
                'base_batta_amount' => $rule->base_amount,
                'night_batta_rate' => $rule->night_amount,
            ]);
        }

        return $this->recalculate($settlement);
    }

    public function recalculate(DriverHireSettlement $settlement): DriverHireSettlement
    {
        $settlement->loadMissing(['expenses', 'iouAdvances']);

        $nightAmount = (float) $settlement->night_count * (float) $settlement->night_batta_rate;
        $batta = (float) $settlement->base_batta_amount + $nightAmount + (float) $settlement->manual_adjustment_amount;
        $expenses = $settlement->expenses
            ->whereIn('status', ['approved', 'partially_approved'])
            ->sum(fn ($expense) => (float) ($expense->approved_amount ?? 0));
        $iou = $settlement->iouAdvances->sum(fn ($advance) => (float) $advance->amount);
        $cashHeld = (float) BookingPaymentReceipt::query()->where('booking_id', $settlement->booking_id)
            ->where('driver_id', $settlement->driver_id)->where('received_via', 'driver')
            ->selectRaw('COALESCE(SUM(amount - driver_company_settled_amount), 0) as balance')->value('balance');

        $settlement->fill([
            'night_batta_amount' => round($nightAmount, 2),
            'approved_batta_amount' => round($batta, 2),
            'approved_expenses_total' => round($expenses, 2),
            'iou_total' => round($iou, 2),
            'cash_collected_unsettled' => round($cashHeld, 2),
            'final_balance' => round($batta + $expenses - $iou - $cashHeld, 2),
        ]);

        $settlement->save();

        return $settlement->refresh();
    }

    public function markSubmitted(DriverHireSettlement $settlement): DriverHireSettlement
    {
        $settlement->update([
            'status' => 'submitted',
            'submitted_at' => Carbon::now(),
        ]);

        return $settlement->refresh();
    }
}
