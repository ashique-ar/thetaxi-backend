<?php

namespace App\Services;

use App\Models\Booking\BookingItem;
use App\Models\Finance\CorporateOnlinePaymentAttempt;
use App\Models\Finance\FinancialAccountSettlement;
use Illuminate\Support\Collection;

class CorporateManagementAnalyticsService
{
    public function report(string $corporateId, array $filters, bool $canViewFinance): array
    {
        $items = $this->items($corporateId, $filters);
        if (! $canViewFinance) {
            $items = $items->map(fn (Collection $row) => $row->put('currency', ''));
        }
        $dimensions = collect(['department', 'division', 'employee', 'service', 'route', 'vehicle_group', 'booking_status', 'approval_result'])
            ->mapWithKeys(fn (string $dimension) => [$dimension => $this->group($items, $dimension)])
            ->all();

        $payload = [
            'period' => ['from' => $filters['date_from'] ?? null, 'to' => $filters['date_to'] ?? null],
            'operational' => [
                'booking_count' => $items->pluck('booking_id')->unique()->count(),
                'trip_count' => $items->count(),
                'finalized_trip_count' => $items->whereNotNull('final_priced_at')->count(),
                'unpriced_trip_count' => $canViewFinance ? $items->where('estimate_available', false)->count() : null,
                'currency_totals' => $items->groupBy('currency')->map(fn (Collection $rows, string $currency) => [
                    'currency' => $currency,
                    'estimated_value' => $rows->contains('estimate_available', false) ? null : $this->roundCurrencyAmount((float) $rows->sum('estimated_amount'), $currency),
                    'finalized_charges' => $this->roundCurrencyAmount((float) $rows->whereNotNull('final_priced_at')->sum('final_amount'), $currency),
                ])->values()->all(),
            ],
            'monthly_trends' => $this->monthly($items),
            'dimensions' => $dimensions,
            'distance' => [
                'contractual_km' => round((float) $items->sum('contractual_km'), 3),
                'operational_km' => round((float) $items->sum('operational_km'), 3),
                'purpose' => [
                    'contractual_km' => 'pricing_snapshot',
                    'operational_km' => 'driver_telemetry_evidence',
                ],
            ],
            'financial_metrics_visible' => $canViewFinance,
        ];

        if ($canViewFinance) {
            $billingPeriodIds = array_values(array_unique(array_filter(array_map('strval', (array) ($filters['billing_period_ids'] ?? [])))));
            if (!$billingPeriodIds && !empty($filters['billing_period_id'])) {
                $billingPeriodIds[] = (string) $filters['billing_period_id'];
            }
            $settlements = $billingPeriodIds
                ? FinancialAccountSettlement::query()
                    ->where('owner_type', 'corporate')
                    ->where('owner_id', $corporateId)
                    ->whereNotIn('status', ['draft', 'void'])
                    ->with('items.booking:id,currency')
                    ->whereIn('id', $billingPeriodIds)
                    ->orderBy('period_start')
                    ->get()
                : collect();
            $onlinePayments = CorporateOnlinePaymentAttempt::query()
                ->whereIn('settlement_id', $settlements->modelKeys())
                ->orderByDesc('created_at')
                ->get(['settlement_id', 'invoice_amount', 'invoice_currency', 'gateway_amount', 'gateway_currency', 'fx_rate_to_lkr', 'gateway_fx_rate_to_lkr', 'fx_rate_at', 'status', 'gateway_transaction_id', 'paid_at', 'created_at'])
                ->groupBy('settlement_id');
            $payload['financial_periods'] = $settlements->map(fn (FinancialAccountSettlement $settlement) => [
                        'id' => (string) $settlement->id,
                        'period_start' => $settlement->period_start?->toDateString(),
                        'period_end' => $settlement->period_end?->toDateString(),
                        'settlement_number' => $settlement->settlement_number,
                        'invoice_number' => $settlement->invoice_number,
                        'status' => $settlement->status,
                        'due_date' => $settlement->due_date?->toDateString(),
                        'currency' => strtoupper((string) (data_get($settlement->billing_terms_snapshot, 'currency')
                            ?? $settlement->items->first()?->booking?->currency
                            ?? '')),
                        'charges_total' => (float) $settlement->charges_total,
                        'payments_total' => (float) $settlement->payments_total,
                        'refunds_total' => (float) $settlement->refunds_total,
                        'adjustments_total' => (float) $settlement->adjustments_total,
                        'outstanding_total' => (float) $settlement->outstanding_total,
                        'conversion_rates' => $this->settlementConversionRates($settlement),
                        'webxpay_payments' => ($onlinePayments->get($settlement->id) ?? collect())->map(fn (CorporateOnlinePaymentAttempt $attempt) => [
                            'invoice_amount' => (float) $attempt->invoice_amount,
                            'invoice_currency' => $attempt->invoice_currency,
                            'gateway_amount' => (float) $attempt->gateway_amount,
                            'gateway_currency' => $attempt->gateway_currency,
                            'fx_rate_to_lkr' => (float) $attempt->fx_rate_to_lkr,
                            'gateway_fx_rate_to_lkr' => (float) $attempt->gateway_fx_rate_to_lkr,
                            'fx_rate_at' => $attempt->fx_rate_at?->toIso8601String(),
                            'status' => $attempt->status,
                            'transaction_reference' => $attempt->gateway_transaction_id,
                            'paid_at' => $attempt->paid_at?->toIso8601String(),
                            'created_at' => $attempt->created_at?->toIso8601String(),
                        ])->values()->all(),
            ])->values()->all();
            if (count($payload['financial_periods']) === 1) {
                $payload['financial_period'] = ['basis' => 'billing_period', 'summary' => $payload['financial_periods'][0]];
            }
        } else {
            unset($payload['operational']['currency_totals']);
            foreach ($payload['monthly_trends'] as &$month) {
                unset($month['estimated_value'], $month['finalized_charges'], $month['unpriced_trip_count']);
            }
            unset($month);
            foreach ($payload['dimensions'] as &$rows) {
                foreach ($rows as &$row) {
                    unset($row['estimated_value'], $row['finalized_charges'], $row['unpriced_trip_count']);
                }
                unset($row);
            }
            unset($rows);
        }

        return $payload;
    }

    private function items(string $corporateId, array $filters): Collection
    {
        return BookingItem::query()
            ->whereHas('booking', function ($query) use ($corporateId, $filters) {
                $query->where('corporate_account_id', $corporateId)
                    ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('corporate_department_id', $id))
                    ->when($filters['division_id'] ?? null, fn ($q, $id) => $q->where('corporate_division_id', $id))
                    ->when($filters['status'] ?? null, function ($q, $status): void {
                        if ($status === 'pending_approval') {
                            $q->whereIn('status', ['pending', 'pending_approval']);
                        } elseif ($status === 'approved') {
                            $q->whereIn('status', ['approved', 'confirmed']);
                        } elseif ($status === 'in_progress') {
                            $q->whereIn('status', ['assigned', 'allocated', 'in_progress']);
                        } else {
                            $q->where('status', $status);
                        }
                    });
            })
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->where('from_date', '>=', TimezoneService::userDateStartUtc($date)))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->where('from_date', '<', TimezoneService::userDateEndExclusiveUtc($date)))
            ->with([
                'booking:id,corporate_account_id,corporate_department_id,corporate_division_id,employee_id,status,approval_status,currency',
                'booking.corporateDepartment:id,name',
                'booking.corporateDivision:id,name',
                'booking.employee:id,user_id',
                'booking.employee.user:id,first_name,last_name',
                'serviceType:id,name',
                'vehicleGroup:id,name',
            ])
            ->get([
                'id', 'booking_id', 'from_date', 'pickup_location', 'dropoff_location',
                'service_type_id', 'vehicle_group_id', 'currency', 'total_price', 'addons',
                'pricing_breakdown', 'discounts', 'final_priced_at', 'lifecycle_data', 'metadata', 'status',
            ])
            ->map(function (BookingItem $item) {
                $pricing = is_array($item->pricing_breakdown) ? $item->pricing_breakdown : [];
                $contractual = data_get($pricing, 'contractual_distance.total_billable_distance')
                    ?? data_get($pricing, 'distance.total_billable_distance')
                    ?? data_get($pricing, 'total_billable_distance');
                $operational = data_get($item->lifecycle_data, 'actual_distance_km')
                    ?? data_get($item->metadata, 'actual_distance_km');

                return collect([
                    'booking_id' => $item->booking_id,
                    'booking_item_id' => $item->id,
                    'month' => optional($item->from_date)->format('Y-m'),
                    'department' => $item->booking?->corporateDepartment?->name ?: 'Unassigned',
                    'division' => $item->booking?->corporateDivision?->name ?: 'Unassigned',
                    'employee' => trim(($item->booking?->employee?->user?->first_name ?? '').' '.($item->booking?->employee?->user?->last_name ?? '')) ?: 'Unknown',
                    'service' => $item->serviceType?->name ?: 'Unassigned',
                    'route' => $this->location($item->pickup_location).' -> '.$this->location($item->dropoff_location),
                    'vehicle_group' => $item->vehicleGroup?->name ?: 'Unassigned',
                    'currency' => strtoupper((string) ($item->currency ?: $item->booking?->currency ?: '')),
                    'booking_status' => $item->booking?->status ?: ($item->status ?: 'unknown'),
                    'approval_result' => $item->booking?->approval_status ?: 'not_required',
                    'final_priced_at' => $item->final_priced_at,
                    'estimate_available' => $item->final_priced_at !== null || $item->hasEstimatedAmount(),
                    'estimated_amount' => $item->final_priced_at !== null || $item->hasEstimatedAmount() ? $item->calculateEstimatedTotalValue() : null,
                    'final_amount' => $item->calculateTotalValue(),
                    'contractual_km' => (float) ($contractual ?? 0),
                    'operational_km' => (float) ($operational ?? 0),
                ]);
            });
    }

    private function group(Collection $items, string $dimension): array
    {
        $groups = [];
        foreach ($items->groupBy($dimension) as $label => $rows) {
            foreach ($rows->groupBy('currency') as $currency => $currencyRows) {
                $unpricedTripCount = $currencyRows->where('estimate_available', false)->count();
                $groups[] = [
                    'label' => $label,
                    'currency' => $currency,
                    'booking_count' => $currencyRows->pluck('booking_id')->unique()->count(),
                    'trip_count' => $currencyRows->count(),
                    'unpriced_trip_count' => $unpricedTripCount,
                    'estimated_value' => $unpricedTripCount ? null : $this->roundCurrencyAmount((float) $currencyRows->sum('estimated_amount'), (string) $currency),
                    'finalized_charges' => $this->roundCurrencyAmount((float) $currencyRows->whereNotNull('final_priced_at')->sum('final_amount'), (string) $currency),
                ];
            }
        }

        return collect($groups)->sortByDesc('trip_count')->values()->all();
    }

    private function monthly(Collection $items): array
    {
        return $items->groupBy('month')->filter(fn ($rows, $month) => filled($month))
            ->flatMap(fn (Collection $rows, string $month) => $rows->groupBy('currency')->map(fn (Collection $currencyRows, string $currency) => [
                'month' => $month,
                'currency' => $currency,
                'bookings' => $currencyRows->pluck('booking_id')->unique()->count(),
                'trips' => $currencyRows->count(),
                'unpriced_trip_count' => $currencyRows->where('estimate_available', false)->count(),
                'estimated_value' => $currencyRows->contains('estimate_available', false) ? null : $this->roundCurrencyAmount((float) $currencyRows->sum('estimated_amount'), $currency),
                'finalized_charges' => $this->roundCurrencyAmount((float) $currencyRows->whereNotNull('final_priced_at')->sum('final_amount'), $currency),
            ]))->sortBy(fn (array $row) => $row['month'].'|'.$row['currency'])->values()->all();
    }

    private function location(mixed $location): string
    {
        if (is_array($location)) {
            return (string) ($location['address'] ?? $location['name'] ?? $location['formatted_address'] ?? 'Unknown');
        }

        return filled($location) ? (string) $location : 'Unknown';
    }

    private function roundCurrencyAmount(float $amount, string $currency): float
    {
        return $currency !== ''
            ? app(CurrencyService::class)->roundAmount($amount, $currency)
            : round($amount, 4);
    }

    private function settlementConversionRates(FinancialAccountSettlement $settlement): array
    {
        $invoiceCurrency = strtoupper((string) (data_get($settlement->billing_terms_snapshot, 'currency')
            ?: $settlement->items->first()?->booking?->currency ?: 'LKR'));

        $itemRates = $settlement->items->flatMap(function ($item) use ($invoiceCurrency) {
            $snapshot = $item->source_snapshot ?? [];
            // shortcut: omit legacy conversions without a captured rate, when historical rate evidence is migrated expose those records too.
            return collect($snapshot['items'] ?? [])->merge($snapshot['payment_conversions'] ?? [])
                ->filter(fn (array $conversion) => isset($conversion['fx_rate_to_invoice'])
                    && is_numeric($conversion['fx_rate_to_invoice'])
                    && (float) $conversion['fx_rate_to_invoice'] > 0)
                ->map(function (array $conversion) use ($invoiceCurrency) {
                    return [
                        'source_currency' => strtoupper((string) ($conversion['source_currency'] ?? $conversion['currency'] ?? $invoiceCurrency)),
                        'invoice_currency' => $invoiceCurrency,
                        'rate' => (float) $conversion['fx_rate_to_invoice'],
                        'rate_at' => $conversion['fx_rate_at'] ?? null,
                        'fx_source' => $conversion['fx_source'] ?? null,
                    ];
                })
                ->filter(fn (array $rate) => $rate['source_currency'] !== $invoiceCurrency && $rate['rate'] > 0);
        });
        $openingRates = collect(data_get($settlement->statement_snapshot, 'opening_balance_breakdown', []))
            ->map(fn (array $conversion) => [
                'source_currency' => strtoupper((string) ($conversion['source_currency'] ?? '')),
                'invoice_currency' => strtoupper((string) ($conversion['invoice_currency'] ?? $invoiceCurrency)),
                'rate' => is_numeric($conversion['fx_rate_to_invoice'] ?? null) ? (float) $conversion['fx_rate_to_invoice'] : null,
                'rate_at' => $conversion['fx_rate_at'] ?? null,
                'fx_source' => $conversion['fx_source'] ?? null,
            ])
            ->filter(fn (array $rate) => $rate['source_currency'] !== $invoiceCurrency
                && is_numeric($rate['rate']) && (float) $rate['rate'] > 0);

        return $itemRates->merge($openingRates)
            ->groupBy(fn (array $rate) => implode('|', [$rate['source_currency'], $rate['invoice_currency'], $rate['rate'], $rate['fx_source']]))
            ->map(function ($rates) {
                $capturedAt = $rates->pluck('rate_at')->filter()->sort()->values();
                return [
                    ...$rates->first(),
                    'rate_at' => $capturedAt->first(),
                    'rate_until' => $capturedAt->last(),
                ];
            })
            ->sortBy(fn (array $rate) => $rate['source_currency'].'|'.$rate['rate'])
            ->values()
            ->all();
    }
}
