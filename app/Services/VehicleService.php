<?php

namespace App\Services;

use App\Models\Vehicle\VehicleGroup;
use App\Models\ServiceType;
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
        $serviceType = $params['service_type'] ?? 'rental_package';
        $limit = $params['limit'] ?? 8;
        $fromDate = isset($params['from_date']) ? Carbon::parse($params['from_date']) : Carbon::now();
        $toDate = isset($params['to_date']) ? Carbon::parse($params['to_date']) : Carbon::now()->addDay();
        $durationDays = $params['duration_days'] ?? 1;

        // Get featured vehicle groups with relationships
        $vehicleGroups = VehicleGroup::with([
            'grade',
            'make', 
            'model',
            'transmission',
            'fuelType',
            'category',
            'class',
            'vehicles' => function ($query) use ($fromDate, $toDate) {
                // Only include available vehicles (not booked during the period)
                $query->where('is_active', true)
                    ->where('status', 'available')
                    ->whereNotExists(function ($subQuery) use ($fromDate, $toDate) {
                        $subQuery->select(DB::raw(1))
                            ->from('bookings')
                            ->whereRaw('bookings.vehicle_id = vehicles.id')
                            ->where('status', '!=', 'cancelled')
                            ->where(function ($q) use ($fromDate, $toDate) {
                                $q->whereBetween('from_date', [$fromDate, $toDate])
                                    ->orWhereBetween('to_date', [$fromDate, $toDate])
                                    ->orWhere(function ($inner) use ($fromDate, $toDate) {
                                        $inner->where('from_date', '<=', $fromDate)
                                            ->where('to_date', '>=', $toDate);
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

        // Format the results similar to BookingFlowService format
        $results = [];
        foreach ($vehicleGroups as $group) {
            $availableVehicles = $group->vehicles;
            $availableCount = $group->vehicles()->count();
            $totalCount = $group->vehicles()->where('is_active', true)->count();

            if ($availableCount > 0) {
                // Get pricing for the vehicle group
                $pricing = $this->getVehicleGroupPricing($group, [
                    'service_type' => $serviceType,
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    'duration_days' => $durationDays
                ]);

                $vehicleData = [
                    'id' => $group->id,
                    'name' => $group->name,
                    'description' => $group->description,
                    'specs' => $group->specs,
                    'images' => $group->images,
                    'is_featured' => $group->is_featured,
                    'available_count' => $availableCount,
                    'total_count' => $totalCount,
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
                    'enhanced_pricing' => [], // Can be extended for discounts/promotions
                    'service_features' => $this->getServiceFeatures($serviceType),
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
     * Get pricing for a vehicle group
     */
    protected function getVehicleGroupPricing(VehicleGroup $group, array $params): array
    {
        try {
            // Use the BookingFlowService to calculate rates
            $pricingParams = [
                'vehicle_group_id' => $group->id,
                'service_type' => $params['service_type'],
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
                'base_amount' => $pricing['base_amount'] ?? 0,
                'currency' => $pricing['currency'] ?? 'LKR',
                'includes_driver' => $this->includesDriver($params['service_type']),
                'includes_fuel' => $this->includesFuel($params['service_type']),
                'total_amount' => $pricing['total_amount'] ?? $pricing['base_amount'] ?? 0,
                'breakdown' => $pricing['breakdown'] ?? []
            ];
        } catch (\Exception $e) {
            // Fallback pricing if service fails
            return [
                'base_amount' => 15000, // Default daily rate
                'currency' => 'LKR',
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
            'rental_package' => [
                'Self Drive Available',
                'With Driver Available',
                'Flexible Duration',
                'Insurance Included'
            ],
            'airport-transfer' => [
                'Professional Driver',
                'Flight Tracking',
                'Meet & Greet'
            ],
            'drop-pickup' => [
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

        return $features[$serviceType] ?? $features['rental_package'];
    }

    /**
     * Check if vehicle group is recommended for service type
     */
    protected function isRecommended(VehicleGroup $group, string $serviceType): bool
    {
        // Business logic for recommendations
        // Could be based on popularity, ratings, etc.
        
        // For rental packages, recommend SUVs and premium vehicles
        if ($serviceType === 'rental_package') {
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
            'airport-transfer',
            'drop-pickup', 
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
            'airport-transfer',
            'drop-pickup',
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
        $serviceType = $params['service_type'] ?? 'rental_package';

        return [
            'id' => Str::uuid(),
            'service_type' => $serviceType,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'from_time' => $fromTime,
            'to_time' => $toTime,
            'pickup_location' => null,
            'dropoff_location' => null,
            'duration_days' => Carbon::parse($fromDate)->diffInDays(Carbon::parse($toDate)) ?: 1,
            'created_at' => Carbon::now()
        ];
    }
}