<?php

namespace App\Services;

use App\Models\Driver\DriverBattaRule;
use App\Models\Driver\DriverHireSettlement;
use Illuminate\Support\Carbon;

class DriverHireSettlementService
{
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

        $settlement->fill([
            'night_batta_amount' => round($nightAmount, 2),
            'approved_batta_amount' => round($batta, 2),
            'approved_expenses_total' => round($expenses, 2),
            'iou_total' => round($iou, 2),
            'final_balance' => round($batta + $expenses - $iou, 2),
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
