<?php

namespace App\Services;

use App\Models\Vehicle\VehicleLease;
use App\Models\Vehicle\VehicleLeasePayment;
use App\Models\Vehicle\VehicleLeaseSchedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class VehicleLeaseAccountingService
{
    private const OPEN_STATUSES = ['active', 'expired'];

    private const RUN_RATE_STATUSES = ['active', 'expired'];

    /**
     * Project the current lease balances and selected-period activity for a vehicle portfolio.
     *
     * All calculations are performed in integer minor units and are kept separate by
     * contract currency. Only activated contracts participate; draft contract terms do not.
     *
     * @param  iterable<int, string>  $vehicleIds
     * @return array{
     *     by_currency: array<string, array<string, float>>,
     *     per_vehicle: array<string, array{
     *         current_lease_id: string|null,
     *         currency: string|null,
     *         monthly_run_rate: float,
     *         outstanding: float,
     *         overdue: float
     *     }>
     * }
     */
    public function portfolioSummary(
        CarbonInterface|string $start,
        CarbonInterface|string $end,
        iterable $vehicleIds
    ): array {
        $periodStart = $this->date($start)->startOfDay();
        $periodEnd = $this->date($end)->endOfDay();

        if ($periodEnd->lt($periodStart)) {
            throw new InvalidArgumentException('The lease accounting period end must be on or after its start.');
        }

        $ids = collect($vehicleIds)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return $this->emptySummary();
        }

        $leases = VehicleLease::query()
            ->whereIn('vehicle_id', $ids)
            ->whereNotNull('activated_at')
            ->with(['release', 'depositDispositions'])
            ->get();

        if ($leases->isEmpty()) {
            return $this->emptySummary();
        }

        $leaseIds = $leases->pluck('id');
        $openLeaseIds = $leases
            ->filter(fn (VehicleLease $lease) => in_array($lease->status, self::OPEN_STATUSES, true)
                && $lease->financial_status === 'active')
            ->pluck('id');

        $schedules = VehicleLeaseSchedule::query()
            ->whereIn('vehicle_lease_id', $leaseIds)
            ->where(function ($query) use ($openLeaseIds, $periodStart, $periodEnd) {
                $query->whereIn('vehicle_lease_id', $openLeaseIds)
                    ->orWhereBetween('due_date', [
                        $periodStart->toDateString(),
                        $periodEnd->toDateString(),
                    ]);
            })
            ->with('allocations.payment')
            ->get()
            ->groupBy('vehicle_lease_id');

        $payments = VehicleLeasePayment::query()
            ->whereIn('vehicle_lease_id', $leaseIds)
            ->where(function ($query) use ($periodStart, $periodEnd) {
                $query->whereBetween('paid_date', [
                    $periodStart->toDateString(),
                    $periodEnd->toDateString(),
                ])->orWhereBetween('reversed_at', [$periodStart, $periodEnd]);
            })
            ->get()
            ->groupBy('vehicle_lease_id');

        foreach ($leases as $lease) {
            $lease->setRelation('schedules', $schedules->get($lease->id, collect()));
            $lease->setRelation('payments', $payments->get($lease->id, collect()));
        }

        return $this->buildSummary($leases, $periodStart, $periodEnd);
    }

    /**
     * @return array{
     *     by_currency: array<string, array<string, float>>,
     *     per_vehicle: array<string, array{
     *         current_lease_id: string|null,
     *         currency: string|null,
     *         monthly_run_rate: float,
     *         outstanding: float,
     *         overdue: float
     *     }>
     * }
     */
    private function buildSummary(
        Collection $leases,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd
    ): array {
        $activatedLeases = $leases
            ->filter(fn (VehicleLease $lease) => $lease->activated_at !== null)
            ->values();
        $currentByVehicle = $this->currentLeasesByVehicle($activatedLeases);
        $currencyTotals = [];
        $perVehicle = [];
        $today = Carbon::now()->startOfDay();

        foreach ($activatedLeases as $lease) {
            $currency = $this->currency($lease->currency);
            $currencyTotals[$currency] ??= $this->emptyCurrencyTotals();

            $schedules = $lease->relationLoaded('schedules')
                ? collect($lease->getRelation('schedules'))
                : collect();
            $payments = $lease->relationLoaded('payments')
                ? collect($lease->getRelation('payments'))
                : collect();
            $release = $lease->relationLoaded('release')
                ? $lease->getRelation('release')
                : null;
            $depositDispositions = $lease->relationLoaded('depositDispositions')
                ? collect($lease->getRelation('depositDispositions'))
                : collect();

            $leaseOutstanding = 0;
            $leaseOverdue = 0;
            $isOpen = in_array($lease->status, self::OPEN_STATUSES, true)
                && $lease->financial_status === 'active';

            foreach ($schedules as $schedule) {
                $amountDue = $this->toMinorUnits($schedule->amount_due);
                $allocated = $this->activeAllocatedMinorUnits($schedule);
                $balance = max(0, $amountDue - $allocated);
                $dueDate = $this->date($schedule->due_date)->startOfDay();

                if ($dueDate->betweenIncluded($periodStart, $periodEnd)) {
                    $currencyTotals[$currency]['scheduled_in_period'] += $amountDue;
                    $currencyTotals[$currency]['allocated_in_period'] += $allocated;
                    if ($isOpen) {
                        $currencyTotals[$currency]['remaining_due_in_period'] += $balance;
                    }
                }

                if ($isOpen) {
                    $leaseOutstanding += $balance;
                    if ($balance > 0 && $dueDate->lt($today)) {
                        $leaseOverdue += $balance;
                    }
                }
            }

            foreach ($payments as $payment) {
                $paidDate = $this->date($payment->paid_date)->startOfDay();
                if ($paidDate->betweenIncluded($periodStart, $periodEnd)) {
                    $currencyTotals[$currency]['cash_paid_in_period'] += $this->toMinorUnits($payment->amount);
                }
                if ($payment->reversed_at
                    && $this->date($payment->reversed_at)->betweenIncluded($periodStart, $periodEnd)) {
                    $currencyTotals[$currency]['payment_reversals_in_period'] += $this->toMinorUnits($payment->amount);
                }
            }

            if ($isOpen) {
                $currencyTotals[$currency]['total_outstanding'] += $leaseOutstanding;
                $currencyTotals[$currency]['overdue'] += $leaseOverdue;
            }

            $releaseIsPending = $release && $release->settlement_status === 'pending';
            $depositCredit = $release ? $this->toMinorUnits($release->deposit_credit) : 0;
            $recordedDepositDispositions = $depositDispositions
                ->where('status', 'recorded')
                ->sum(fn ($disposition) => $this->toMinorUnits($disposition->amount));
            $currencyTotals[$currency]['refundable_deposit_asset'] += max(
                0,
                $this->toMinorUnits($lease->deposit_paid_amount)
                    - $depositCredit
                    - $recordedDepositDispositions
            );

            if ($releaseIsPending) {
                $netSettlement = $this->toMinorUnits($release->net_settlement_amount);
                if ($netSettlement > 0) {
                    $currencyTotals[$currency]['pending_release_payable'] += $netSettlement;
                } elseif ($netSettlement < 0) {
                    $currencyTotals[$currency]['pending_release_receivable'] += abs($netSettlement);
                }
            } elseif ($release?->settlement_status === 'settled'
                && $release->settled_at
                && $this->date($release->settled_at)->betweenIncluded($periodStart, $periodEnd)) {
                $settledAmount = $this->toMinorUnits(
                    $release->settlement_amount ?? abs((float) $release->net_settlement_amount)
                );
                if ($release->settlement_direction === 'receivable_from_provider') {
                    $currencyTotals[$currency]['release_cash_received_in_period'] += $settledAmount;
                } elseif ($release->settlement_direction === 'payable_to_provider') {
                    $currencyTotals[$currency]['release_cash_paid_in_period'] += $settledAmount;
                }
            }

            if ($lease->deposit_paid_date
                && $this->date($lease->deposit_paid_date)->betweenIncluded($periodStart, $periodEnd)) {
                $currencyTotals[$currency]['refundable_deposit_paid_in_period'] +=
                    $this->toMinorUnits($lease->deposit_paid_amount);
            }
            if ($lease->down_payment_paid_date
                && $this->date($lease->down_payment_paid_date)->betweenIncluded($periodStart, $periodEnd)) {
                $currencyTotals[$currency]['down_payment_paid_in_period'] +=
                    $this->toMinorUnits($lease->down_payment);
            }
            foreach ($depositDispositions as $disposition) {
                $transactionDate = $this->date($disposition->transaction_date)->startOfDay();
                if ($transactionDate->betweenIncluded($periodStart, $periodEnd)) {
                    $amount = $this->toMinorUnits($disposition->amount);
                    if ($disposition->disposition_type === 'return_received') {
                        $currencyTotals[$currency]['refundable_deposit_cash_received_in_period'] += $amount;
                    } elseif ($disposition->disposition_type === 'forfeited') {
                        $currencyTotals[$currency]['refundable_deposit_forfeited_in_period'] += $amount;
                    } elseif ($disposition->disposition_type === 'offset') {
                        $currencyTotals[$currency]['refundable_deposit_offset_in_period'] += $amount;
                    }
                }
                if ($disposition->reversed_at
                    && $this->date($disposition->reversed_at)->betweenIncluded($periodStart, $periodEnd)) {
                    $currencyTotals[$currency]['deposit_disposition_reversals_in_period'] +=
                        $this->toMinorUnits($disposition->amount);
                }
            }

            $vehicleId = (string) $lease->vehicle_id;
            if (($currentByVehicle[$vehicleId] ?? null) !== $lease) {
                continue;
            }

            $monthlyRunRate = $this->monthlyRunRateMinorUnits($lease, $leaseOutstanding);
            $currencyTotals[$currency]['monthly_run_rate'] += $monthlyRunRate;
            $perVehicle[$vehicleId] = [
                'current_lease_id' => $lease->id,
                'currency' => $currency,
                'monthly_run_rate' => $monthlyRunRate,
                'outstanding' => $leaseOutstanding,
                'overdue' => $leaseOverdue,
            ];
        }

        ksort($currencyTotals);
        ksort($perVehicle);

        return [
            'by_currency' => collect($currencyTotals)
                ->map(fn (array $totals) => $this->currencyTotalsToDecimal($totals))
                ->all(),
            'per_vehicle' => collect($perVehicle)
                ->map(fn (array $metrics) => [
                    ...$metrics,
                    'monthly_run_rate' => $this->fromMinorUnits($metrics['monthly_run_rate']),
                    'outstanding' => $this->fromMinorUnits($metrics['outstanding']),
                    'overdue' => $this->fromMinorUnits($metrics['overdue']),
                ])
                ->all(),
        ];
    }

    /**
     * The current commitment is the latest unpaid active/expired contract for each vehicle.
     *
     * @return array<string, VehicleLease>
     */
    private function currentLeasesByVehicle(Collection $leases): array
    {
        $current = [];

        foreach ($leases as $lease) {
            if (! in_array($lease->status, self::RUN_RATE_STATUSES, true)
                || $lease->financial_status !== 'active') {
                continue;
            }

            $vehicleId = (string) $lease->vehicle_id;
            $existing = $current[$vehicleId] ?? null;
            if (! $existing || $this->leaseSortKey($lease) > $this->leaseSortKey($existing)) {
                $current[$vehicleId] = $lease;
            }
        }

        return $current;
    }

    private function monthlyRunRateMinorUnits(VehicleLease $lease, int $outstanding): int
    {
        if ($outstanding <= 0
            || ! in_array($lease->status, self::RUN_RATE_STATUSES, true)
            || $lease->financial_status !== 'active') {
            return 0;
        }

        $months = match ($lease->payment_frequency) {
            'quarterly' => 3,
            'semiannual' => 6,
            'annual' => 12,
            default => 1,
        };
        $installment = max(0, $this->toMinorUnits($lease->installment_amount));
        $normalized = intdiv($installment + intdiv($months, 2), $months);

        return min($normalized, $outstanding);
    }

    private function activeAllocatedMinorUnits(object $schedule): int
    {
        $allocations = method_exists($schedule, 'relationLoaded') && $schedule->relationLoaded('allocations')
            ? collect($schedule->getRelation('allocations'))
            : collect();

        return $allocations->sum(function ($allocation): int {
            if ($allocation->reversed_at !== null) {
                return 0;
            }

            $payment = method_exists($allocation, 'relationLoaded') && $allocation->relationLoaded('payment')
                ? $allocation->getRelation('payment')
                : null;

            return $payment && $payment->status === 'recorded'
                ? $this->toMinorUnits($allocation->amount)
                : 0;
        });
    }

    private function currencyTotalsToDecimal(array $totals): array
    {
        return collect($totals)
            ->map(fn (int $amount) => $this->fromMinorUnits($amount))
            ->all();
    }

    private function emptySummary(): array
    {
        return ['by_currency' => [], 'per_vehicle' => []];
    }

    private function emptyCurrencyTotals(): array
    {
        return [
            'monthly_run_rate' => 0,
            'scheduled_in_period' => 0,
            'allocated_in_period' => 0,
            'remaining_due_in_period' => 0,
            'cash_paid_in_period' => 0,
            'payment_reversals_in_period' => 0,
            'release_cash_paid_in_period' => 0,
            'release_cash_received_in_period' => 0,
            'down_payment_paid_in_period' => 0,
            'refundable_deposit_paid_in_period' => 0,
            'refundable_deposit_cash_received_in_period' => 0,
            'refundable_deposit_forfeited_in_period' => 0,
            'refundable_deposit_offset_in_period' => 0,
            'deposit_disposition_reversals_in_period' => 0,
            'overdue' => 0,
            'total_outstanding' => 0,
            'refundable_deposit_asset' => 0,
            'pending_release_payable' => 0,
            'pending_release_receivable' => 0,
        ];
    }

    private function currency(?string $currency): string
    {
        $normalized = strtoupper(trim((string) $currency));

        return $normalized !== '' ? $normalized : 'LKR';
    }

    private function leaseSortKey(VehicleLease $lease): string
    {
        $startDate = $lease->start_date?->format('Y-m-d') ?? '0000-00-00';
        $createdAt = $lease->created_at?->format('Y-m-d H:i:s.u') ?? '0000-00-00 00:00:00.000000';

        return "{$startDate}|{$createdAt}|{$lease->id}";
    }

    private function date(CarbonInterface|string $value): Carbon
    {
        return $value instanceof CarbonInterface
            ? Carbon::instance($value)
            : Carbon::parse($value);
    }

    private function toMinorUnits(mixed $amount): int
    {
        $value = trim((string) ($amount ?? '0'));
        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $value, $matches)) {
            return (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);
        }

        $negative = ($matches[1] ?? '') === '-';
        $whole = (int) $matches[2];
        $fraction = str_pad($matches[3] ?? '', 3, '0');
        $minor = ($whole * 100) + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $minor++;
        }

        return $negative ? -$minor : $minor;
    }

    private function fromMinorUnits(int $amount): float
    {
        return round($amount / 100, 2);
    }
}
