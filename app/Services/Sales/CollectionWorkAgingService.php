<?php

namespace App\Services\Sales;

use Carbon\CarbonInterface;

class CollectionWorkAgingService
{
    public function derive(
        float $scheduledAmount,
        float $allocatedAmount,
        CarbonInterface $dueDate,
        CarbonInterface $asOf,
        int $reminderOffsetDays = 0,
    ): array {
        $outstanding = max(0, round($scheduledAmount - $allocatedAmount, 4));
        $due = $dueDate->copy()->startOfDay();
        $today = $asOf->copy()->startOfDay();
        $daysUntilDue = (int) $today->diffInDays($due, false);
        $daysOverdue = $outstanding > 0 && $daysUntilDue < 0 ? abs($daysUntilDue) : 0;

        return [
            'outstanding_amount' => $outstanding,
            'days_overdue' => $daysOverdue,
            'aging_bucket' => match (true) {
                $outstanding <= 0 => 'paid',
                $daysOverdue === 0 && $daysUntilDue === 0 => 'due_today',
                $daysOverdue === 0 => 'not_due',
                $daysOverdue <= 30 => '1_30',
                $daysOverdue <= 60 => '31_60',
                $daysOverdue <= 90 => '61_90',
                default => '91_plus',
            },
            'work_status' => match (true) {
                $outstanding <= 0 => 'completed',
                $daysUntilDue < 0 => 'overdue',
                $daysUntilDue === 0 => 'due',
                $daysUntilDue <= max(0, $reminderOffsetDays) => 'upcoming',
                default => 'open',
            },
        ];
    }
}
