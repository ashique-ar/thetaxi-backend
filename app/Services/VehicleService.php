<?php

namespace App\Services;

use App\Models\Vehicle\VehicleGroup;
use App\Models\Service\ServiceType;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VehicleService
{
    protected PricingVariableService $pricingVariableService;
    protected CurrencyService $currencyService;
    protected BookingFlowService $bookingFlowService;

    public function __construct(
        PricingVariableService $pricingVariableService,
        CurrencyService $currencyService,
        BookingFlowService $bookingFlowService
    ) {
        $this->pricingVariableService = $pricingVariableService;
        $this->currencyService = $currencyService;
        $this->bookingFlowService = $bookingFlowService;
    }

    /**
     * Get featured vehicles for the homepage
     * 
     * @param array $params
     * @return array
     */
    public function getFeaturedVehicles(array $params = []): array
    {
        $serviceType = $params['service_type'] ?? 'ride_now';
        $limit = $params['limit'] ?? 8;
        $fromDate = isset($params['from_date']) ? Carbon::parse($params['from_date']) : Carbon::now();
        $toDate = isset($params['to_date']) ? Carbon::parse($params['to_date']) : Carbon::now()->addDay();
        $durationDays = $params['duration_days'] ?? 1;

        // Get featured vehicle groups with relationships - OPTIMIZED with single query
        $vehicleGroups = VehicleGroup::with([
            'grade',
            'make',
            'model',
            'transmission',
            'fuelType',
            'category',
            'class',
            'vehicles' => function ($query) use ($fromDate, $toDate) {
                $query->where('is_active', true)
                    ->where('status', 'available')
                    ->whereNotExists(function ($subQuery) use ($fromDate, $toDate) {
                        $subQuery->selectRaw('1')
                            ->from('booking_items')
                            ->join('bookings', 'booking_items.booking_id', '=', 'bookings.id')
                            ->whereColumn('booking_items.vehicle_id', 'vehicles.id')
                            ->where('bookings.status', '!=', 'cancelled')
                            ->where(function ($q) use ($fromDate, $toDate) {
                                $q->whereBetween('booking_items.from_date', [$fromDate, $toDate])
                                    ->orWhereBetween('booking_items.to_date', [$fromDate, $toDate])
                                    ->orWhere(function ($inner) use ($fromDate, $toDate) {
                                        $inner->where('booking_items.from_date', '<=', $fromDate)
                                            ->where('booking_items.to_date', '>=', $toDate);
                                    });
                            });
                    });
            }
        ])
            ->where('is_active', true)
            ->where('is_featured', true)
            ->orderBy('name')
            ->limit($limit)
            ->get();

        // Get total counts in separate query (only once per group, not per vehicle)
        $vehicleCounts = DB::table('vehicle_groups')
            ->leftJoin('vehicles', 'vehicle_groups.id', '=', 'vehicles.vehicle_group_id')
            ->whereIn('vehicle_groups.id', $vehicleGroups->pluck('id'))
            ->where('vehicles.is_active', true)
            ->select('vehicle_groups.id')
            ->selectRaw('COUNT(vehicles.id) as total_count')
            ->groupBy('vehicle_groups.id')
            ->pluck('total_count', 'id');

        // Batch pricing calculation instead of loop-based
        $pricingParams = [
            'service_type' => $serviceType,
            'from_date' => $fromDate->format('Y-m-d'),
            'to_date' => $toDate->format('Y-m-d'),
            'from_time' => '09:00',
            'to_time' => '18:00',
            'duration_days' => $durationDays,
            'pickup_location' => null,
            'dropoff_location' => null
        ];

        // Format the results similar to BookingFlowService format
        $results = [];
        $serviceFeatures = $this->getServiceFeatures($serviceType);

        foreach ($vehicleGroups as $group) {
            $availableVehicles = $group->vehicles;
            $availableCount = $availableVehicles->count();

            if ($availableCount > 0) {
                // Simplified pricing call with batch-friendly params
                $pricing = $this->getVehicleGroupPricingFast($group, $pricingParams);

                $vehicleData = [
                    'id' => $group->id,
                    'name' => $group->name,
                    'description' => $group->description,
                    'specs' => $group->specs,
                    'images' => $group->images,
                    'is_featured' => $group->is_featured,
                    'available_count' => $availableCount,
                    'total_count' => $vehicleCounts[$group->id] ?? $availableCount,
                    'seating_capacity' => $this->getGroupSeatingCapacity($availableVehicles),
                    'grade' => $group->grade ? [
                        'id' => $group->grade->id,
                        'name' => $group->grade->name
                    ] : null,
                    'make' => $group->make ? [
                        'id' => $group->make->id,
                        'name' => $group->make->name
                    ] : null,
                    'model' => $group->model ? [
                        'id' => $group->model->id,
                        'name' => $group->model->name
                    ] : null,
                    'transmission' => $group->transmission ? [
                        'id' => $group->transmission->id,
                        'name' => $group->transmission->name
                    ] : null,
                    'fuel_type' => $group->fuelType ? [
                        'id' => $group->fuelType->id,
                        'name' => $group->fuelType->name
                    ] : null,
                    'category' => $group->category ? [
                        'id' => $group->category->id,
                        'name' => ['name' => $group->category->name]
                    ] : null,
                    'class' => $group->class ? [
                        'id' => $group->class->id,
                        'name' => $group->class->name
                    ] : null,
                    'pricing_info' => $pricing,
                    'enhanced_pricing' => [],
                    'service_features' => $serviceFeatures,
                    'recommended' => $this->isRecommended($group, $serviceType)
                ];

                $results[] = $vehicleData;
            }
        }

        return [
            'data' => $results,
            'total' => count($results),
            'service_type' => $serviceType,
            'duration_days' => $durationDays
        ];
    }

    /**
     * Get pricing for a vehicle group - FAST VERSION for featured vehicles
     * Uses simplified calculation to avoid timeout
     */
    protected function getVehicleGroupPricingFast(VehicleGroup $group, array $params): array
    {
        try {
            // Convert service_type slug to service_type_id
            $serviceTypeId = $this->getServiceTypeId($params['service_type']);

            // Use the BookingFlowService to calculate rates with vehicle_group_id
            $pricingParams = [
                'vehicle_group_id' => $group->id,
                'service_type' => $params['service_type'],
                'service_type_id' => $serviceTypeId,
                'from_date' => $params['from_date'],
                'to_date' => $params['to_date'],
                'from_time' => $params['from_time'],
                'to_time' => $params['to_time'],
                'duration_days' => $params['duration_days'],
                'pickup_location' => $params['pickup_location'],
                'dropoff_location' => $params['dropoff_location']
            ];

            $pricing = $this->bookingFlowService->calculatePricing($pricingParams);

            return [
                'base_amount' => $pricing['base_amount'] ?? $pricing['summary']['subtotal'] ?? 0,
                'currency' => $pricing['currency'] ?? 'LKR',
                'includes_driver' => $this->includesDriver($params['service_type']),
                'includes_fuel' => $this->includesFuel($params['service_type']),
                'total_amount' => $pricing['summary']['total'] ?? $pricing['total_amount'] ?? $pricing['base_amount'] ?? 0,
                'breakdown' => $pricing['breakdown'] ?? $pricing['base_pricing']['breakdown'] ?? []
            ];
        } catch (\Exception $e) {
            // Fallback pricing if service fails
            return [
                'base_amount' => 15000,
                'currency' => config('booking.base_currency', 'LKR'),
                'includes_driver' => $this->includesDriver($params['service_type']),
                'includes_fuel' => $this->includesFuel($params['service_type']),
                'total_amount' => 15000,
                'breakdown' => []
            ];
        }
    }

    /**
     * Get service type ID from slug/name
     */
    protected function getServiceTypeId(string $serviceTypeSlug): ?string
    {
        $serviceType = ServiceType::where('slug', $serviceTypeSlug)
            ->orWhere('name', $serviceTypeSlug)
            ->first();

        return $serviceType?->id;
    }

    /**
     * Get pricing for a vehicle group
     */
    protected function getVehicleGroupPricing(VehicleGroup $group, array $params): array
    {
        try {
            // Convert service_type slug to service_type_id
            $serviceTypeId = $this->getServiceTypeId($params['service_type']);

            // Use the BookingFlowService to calculate rates
            $pricingParams = [
                'vehicle_group_id' => $group->id,
                'service_type' => $params['service_type'],
                'service_type_id' => $serviceTypeId,
                'from_date' => $params['from_date']->format('Y-m-d'),
                'to_date' => $params['to_date']->format('Y-m-d'),
                'from_time' => '09:00',
                'to_time' => '18:00',
                'duration_days' => $params['duration_days'],
                'pickup_location' => null,
                'dropoff_location' => null
            ];

            $pricing = $this->bookingFlowService->calculatePricing($pricingParams);

            return [
                'base_amount' => $pricing['base_amount'] ?? $pricing['summary']['subtotal'] ?? 0,
                'currency' => $pricing['currency'] ?? 'LKR',
                'includes_driver' => $this->includesDriver($params['service_type']),
                'includes_fuel' => $this->includesFuel($params['service_type']),
                'total_amount' => $pricing['summary']['total'] ?? $pricing['total_amount'] ?? $pricing['base_amount'] ?? 0,
                'breakdown' => $pricing['breakdown'] ?? $pricing['base_pricing']['breakdown'] ?? []
            ];
        } catch (\Exception $e) {
            // Fallback pricing if service fails
            return [
                'base_amount' => 15000,
                'currency' => config('booking.base_currency', 'LKR'),
                'includes_driver' => $this->includesDriver($params['service_type']),
                'includes_fuel' => $this->includesFuel($params['service_type']),
                'total_amount' => 15000,
                'breakdown' => []
            ];
        }
    }

    /**
     * Get seating capacity from vehicle group's vehicles
     */
    protected function getGroupSeatingCapacity(Collection $vehicles): ?int
    {
        if ($vehicles->isEmpty()) {
            return null;
        }

        // Get the most common seating capacity
        $capacities = $vehicles->pluck('seats')->filter()->values();
        if ($capacities->isEmpty()) {
            return null;
        }

        return $capacities->mode()[0] ?? $capacities->first();
    }

    /**
     * Get service-specific features
     */
    protected function getServiceFeatures(string $serviceType): array
    {
        $features = [
            'ride_now' => [
                'With Driver Available',
                'Flexible Duration',
                'Insurance Included'
            ],
            'day_rental' => [
                'Professional Driver',
                'Flexible Itinerary',
                'Full Day Coverage',
                'Fuel Included'
            ],
            'airport_transfers' => [
                'Professional Driver',
                'Flight Tracking',
                'Meet & Greet'
            ],
            'point_to_point' => [
                'Door to Door Service',
                'Professional Driver',
                'Flexible Timing'
            ],
            'corporate-transport' => [
                'Corporate Rates',
                'Professional Service',
                'Invoice Facility'
            ]
        ];

        return $features[$serviceType] ?? $features['ride_now'];
    }

    /**
     * Check if vehicle group is recommended for service type
     */
    protected function isRecommended(VehicleGroup $group, string $serviceType): bool
    {
        // Business logic for recommendations
        // Could be based on popularity, ratings, etc.

        // For rental packages, recommend SUVs and premium vehicles
        if ($serviceType === 'ride_now' || $serviceType === 'day_rental') {
            $categoryName = $group->category?->name ?? '';
            return in_array(strtolower($categoryName), ['suv', 'premium', 'luxury']);
        }

        return false;
    }

    /**
     * Check if service type includes driver
     */
    protected function includesDriver(string $serviceType): bool
    {
        return in_array($serviceType, [
            'airport_transfers',
            'point_to_point',
            'corporate-transport',
            'custom-tour'
        ]);
    }

    /**
     * Check if service type includes fuel
     */
    protected function includesFuel(string $serviceType): bool
    {
        return in_array($serviceType, [
            'airport_transfers',
            'point_to_point',
            'corporate-transport',
            'custom-tour'
        ]);
    }

    /**
     * Create a search session for featured vehicles
     */
    public function createFeaturedVehicleSearch(array $params = []): array
    {
        $fromDate = $params['from_date'] ?? Carbon::now()->format('Y-m-d');
        $toDate = $params['to_date'] ?? Carbon::now()->addDay()->format('Y-m-d');
        $fromTime = $params['from_time'] ?? '09:00';
        $toTime = $params['to_time'] ?? '18:00';
        $serviceType = $params['service_type'] ?? 'ride_now';

        return [
            'id' => Str::uuid(),
            'service_type' => $serviceType,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'from_time' => $fromTime,
            'to_time' => $toTime,
            'pickup_location' => null,
            'dropoff_location' => null,
            'duration_days' => Carbon::parse($fromDate)->diffInDays(Carbon::parse($toDate)) + 1 ?: 1,
            'created_at' => Carbon::now()
        ];
    }
}
