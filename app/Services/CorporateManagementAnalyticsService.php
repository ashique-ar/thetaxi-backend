<?php

namespace App\Services;

use App\Models\Booking\BookingItem;
use App\Models\Booking\Booking;
use Illuminate\Support\Collection;

class CorporateManagementAnalyticsService
{
    public function __construct(private readonly CorporateFinancialProjectionService $finance)
    {
    }

    public function report(string $corporateId, array $filters, bool $canViewFinance): array
    {
        $items = $this->items($corporateId, $filters);
        $dimensions = collect(['department', 'division', 'employee', 'service', 'route', 'vehicle_group', 'booking_status', 'approval_result'])
            ->mapWithKeys(fn (string $dimension) => [$dimension => $this->group($items, $dimension)])
            ->all();

        $payload = [
            'period' => ['from' => $filters['date_from'] ?? null, 'to' => $filters['date_to'] ?? null],
            'operational' => [
                'booking_count' => $items->pluck('booking_id')->unique()->count(),
                'trip_count' => $items->count(),
                'finalized_trip_count' => $items->whereNotNull('final_priced_at')->count(),
                'estimated_booking_value' => round((float) $items->sum('estimated_amount'), 2),
                'finalized_charges' => round((float) $items->whereNotNull('final_priced_at')->sum('final_amount'), 2),
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
            $payload['financial'] = $this->finance->accountSummary($corporateId);
            $payload['financial_period'] = [
                'basis' => 'travel_date',
                'summary' => $this->finance->summarizeBookings(
                    Booking::query()->whereIn('id', $items->pluck('booking_id')->unique())->get(),
                    $items->pluck('booking_item_id')->unique(),
                ),
            ];
        } else {
            unset($payload['operational']['estimated_booking_value'], $payload['operational']['finalized_charges']);
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
        return $items->groupBy($dimension)->map(fn (Collection $rows, string $label) => [
            'label' => $label,
            'booking_count' => $rows->pluck('booking_id')->unique()->count(),
            'trip_count' => $rows->count(),
            'estimated_value' => round((float) $rows->sum('estimated_amount'), 2),
            'finalized_charges' => round((float) $rows->whereNotNull('final_priced_at')->sum('final_amount'), 2),
        ])->sortByDesc('trip_count')->values()->all();
    }

    private function monthly(Collection $items): array
    {
        return $items->groupBy('month')->filter(fn ($rows, $month) => filled($month))
            ->map(fn (Collection $rows, string $month) => [
                'month' => $month,
                'bookings' => $rows->pluck('booking_id')->unique()->count(),
                'trips' => $rows->count(),
                'estimated_value' => round((float) $rows->sum('estimated_amount'), 2),
                'finalized_charges' => round((float) $rows->whereNotNull('final_priced_at')->sum('final_amount'), 2),
            ])->sortKeys()->values()->all();
    }

    private function location(mixed $location): string
    {
        if (is_array($location)) {
            return (string) ($location['address'] ?? $location['name'] ?? $location['formatted_address'] ?? 'Unknown');
        }

        return filled($location) ? (string) $location : 'Unknown';
    }
}
