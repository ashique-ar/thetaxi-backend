<?php

namespace App\Services;

use App\Models\Booking\BookingItem;
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
                'currency_totals' => $items->groupBy('currency')->map(fn (Collection $rows, string $currency) => [
                    'currency' => $currency,
                    'estimated_value' => app(CurrencyService::class)->roundAmount((float) $rows->sum('estimated_amount'), $currency ?: 'LKR'),
                    'finalized_charges' => app(CurrencyService::class)->roundAmount((float) $rows->whereNotNull('final_priced_at')->sum('final_amount'), $currency ?: 'LKR'),
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
            $settlement = ($filters['billing_period_id'] ?? null)
                ? FinancialAccountSettlement::query()
                    ->where('owner_type', 'corporate')
                    ->where('owner_id', $corporateId)
                    ->whereNotIn('status', ['draft', 'void'])
                    ->with('items.booking:id,currency')
                    ->find($filters['billing_period_id'])
                : null;
            if ($settlement) {
                $payload['financial_period'] = [
                    'basis' => 'billing_period',
                    'summary' => [
                        'period_start' => $settlement->period_start?->toDateString(),
                        'period_end' => $settlement->period_end?->toDateString(),
                        'settlement_number' => $settlement->settlement_number,
                        'invoice_number' => $settlement->invoice_number,
                        'currency' => strtoupper((string) (data_get($settlement->billing_terms_snapshot, 'currency')
                            ?? $settlement->items->first()?->booking?->currency
                            ?? '')),
                        'charges_total' => (float) $settlement->charges_total,
                        'payments_total' => (float) $settlement->payments_total,
                        'refunds_total' => (float) $settlement->refunds_total,
                        'adjustments_total' => (float) $settlement->adjustments_total,
                        'outstanding_total' => (float) $settlement->outstanding_total,
                    ],
                ];
            }
        } else {
            unset($payload['operational']['currency_totals']);
            foreach ($payload['monthly_trends'] as &$month) {
                unset($month['estimated_value'], $month['finalized_charges']);
            }
            unset($month);
            foreach ($payload['dimensions'] as &$rows) {
                foreach ($rows as &$row) {
                    unset($row['estimated_value'], $row['finalized_charges']);
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
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('from_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('from_date', '<=', $date))
            ->with(['booking.corporateDepartment', 'booking.corporateDivision', 'booking.employee.user', 'serviceType', 'vehicleGroup'])
            ->get()
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
                    'estimated_amount' => (float) ($item->total_price ?? 0),
                    'final_amount' => (float) (data_get($pricing, 'final_total') ?? data_get($pricing, 'total_amount') ?? $item->total_price ?? 0),
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
                $groups[] = [
                    'label' => $label,
                    'currency' => $currency,
                    'booking_count' => $currencyRows->pluck('booking_id')->unique()->count(),
                    'trip_count' => $currencyRows->count(),
                    'estimated_value' => app(CurrencyService::class)->roundAmount((float) $currencyRows->sum('estimated_amount'), $currency ?: 'LKR'),
                    'finalized_charges' => app(CurrencyService::class)->roundAmount((float) $currencyRows->whereNotNull('final_priced_at')->sum('final_amount'), $currency ?: 'LKR'),
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
                'estimated_value' => app(CurrencyService::class)->roundAmount((float) $currencyRows->sum('estimated_amount'), $currency ?: 'LKR'),
                'finalized_charges' => app(CurrencyService::class)->roundAmount((float) $currencyRows->whereNotNull('final_priced_at')->sum('final_amount'), $currency ?: 'LKR'),
            ]))->sortBy(fn (array $row) => $row['month'].'|'.$row['currency'])->values()->all();
    }

    private function location(mixed $location): string
    {
        if (is_array($location)) {
            return (string) ($location['address'] ?? $location['name'] ?? $location['formatted_address'] ?? 'Unknown');
        }

        return filled($location) ? (string) $location : 'Unknown';
    }
}
