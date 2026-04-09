<?php

namespace App\Http\Controllers;

use App\Models\Vehicle\VehicleGroup;
use App\Models\BookingSearch;
use App\Models\Service\ServiceType;
use App\Models\Service\ServicePackage;
use App\Services\BookingFlowService;
use App\Services\CartService;
use App\Services\WebsiteSettingsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VehicleController extends Controller
{
    protected BookingFlowService $bookingFlowService;
    protected CartService $cartService;
    protected WebsiteSettingsService $websiteSettingsService;

    public function __construct(
        BookingFlowService $bookingFlowService,
        CartService $cartService,
        WebsiteSettingsService $websiteSettingsService
    )
    {
        $this->bookingFlowService = $bookingFlowService;
        $this->cartService = $cartService;
        $this->websiteSettingsService = $websiteSettingsService;
    }
    
    /**
     * Display vehicle details with booking functionality
     */
    public function show(Request $request, string $id)
    {
        // Find the vehicle group
        $vehicleGroup = VehicleGroup::with([
            'grade',
            'make',
            'model',
            'category',
            'class',
            'transmission',
            'fuelType',
        ])->withCount('vehicles')->findOrFail($id);

        // Get search context if provided (legacy path)
        $searchId = $request->query('search');
        $preset = strtolower((string) $request->query('preset', ''));
        $search = null;
        $searchData = [];
        
        if ($searchId) {
            $search = BookingSearch::find($searchId);
            if ($search) {
                $searchData = [
                    'service_type' => $search->service_type ?? 'point_to_point',
                    'pickup_date' => $search->from_date ?? now()->format('Y-m-d'),
                    'return_date' => $search->to_date ?? now()->addDay()->format('Y-m-d'),
                    'pickup_time' => $search->from_time ?? '10:00',
                    'return_time' => $search->to_time ?? '18:00',
                    'pickup_location' => $search->pickup_location ?? '',
                    'dropoff_location' => $search->dropoff_location ?? '',
                    'pickup_lat' => $search->pickup_lat,
                    'pickup_lng' => $search->pickup_lng,
                    'dropoff_lat' => $search->dropoff_lat,
                    'dropoff_lng' => $search->dropoff_lng,
                ];
            }
        }

        // Preset path (used by rate chart): force direct day_rental defaults
        if (in_array($preset, ['daily', 'monthly'], true)) {
            $presetDays = $preset === 'monthly' ? 30 : 1;
            $pickupDate = Carbon::today();
            $returnDate = $pickupDate->copy()->addDays($presetDays - 1);

            $searchData = [
                'service_type' => 'day_rental',
                'pickup_date' => $pickupDate->format('Y-m-d'),
                'return_date' => $returnDate->format('Y-m-d'),
                'pickup_time' => '10:00',
                'return_time' => '10:00',
                'pickup_location' => 'Colombo, Sri Lanka',
                'dropoff_location' => 'Colombo, Sri Lanka',
                'pickup_lat' => 6.9271,
                'pickup_lng' => 79.8612,
                'dropoff_lat' => 6.9271,
                'dropoff_lng' => 79.8612,
                'date' => $pickupDate->format('Y-m-d'),
                'time' => '10:00',
                'num_days' => $presetDays,
            ];
            $search = null;
        }
        
        $configuredDefaultServiceType = (string) $this->websiteSettingsService->get('default_service_type', 'day_rental');
        $defaultServiceTypeQuery = ServiceType::publicContext()->where('is_active', true);
        if (Str::isUuid($configuredDefaultServiceType)) {
            $defaultServiceTypeQuery->where('id', $configuredDefaultServiceType);
        } else {
            $defaultServiceTypeQuery->where('code', $configuredDefaultServiceType);
        }
        $defaultServiceType = $defaultServiceTypeQuery->value('code') ?? 'day_rental';

        // Default search data if no search context
        if (empty($searchData)) {
            $searchData = [
                'service_type' => $defaultServiceType,
                'pickup_date' => now()->format('Y-m-d'),
                'return_date' => now()->format('Y-m-d'),
                'pickup_time' => '10:00',
                'return_time' => '10:00',
                'pickup_location' => 'Colombo, Sri Lanka',
                'dropoff_location' => 'Colombo, Sri Lanka',
                'pickup_lat' => 6.9271,
                'pickup_lng' => 79.8612,
                'dropoff_lat' => 6.9271,
                'dropoff_lng' => 79.8612,
                'date' => now()->format('Y-m-d'),
                'time' => '10:00',
                'num_days' => 1,
            ];
        }

        // Get available service types
        $serviceTypes = ServiceType::publicContext()
            ->whereNull('deleted_at')
            ->select('id', 'code', 'name', 'description')
            ->get();

        // Calculate initial pricing based on search data
        $pricing = $this->calculatePricing($vehicleGroup, $searchData);
        
        return view('vehicle-details', compact(
            'vehicleGroup',
            'searchData',
            'serviceTypes',
            'pricing',
            'search',
            'preset'
        ));
    }

    /**
     * Calculate pricing for vehicle with given parameters
     */
    private function calculatePricing($vehicleGroup, $searchData)
    {
        try {
            $serviceType = $this->resolveServiceTypeModel((string) ($searchData['service_type'] ?? ''));
            if (!$serviceType) {
                return ['base_amount' => 0, 'currency' => 'LKR', 'error' => 'Invalid service type'];
            }

            $pricingParams = $this->buildAvailabilityParams($searchData, $vehicleGroup, $serviceType);

            // Get pricing from BookingFlowService
            $availabilityData = $this->bookingFlowService->getAvailableVehicleGroups($pricingParams, true);
            $groups = $availabilityData['data'] ?? [];

            foreach ($groups as $vehicleData) {
                if ($vehicleData['id'] == $vehicleGroup->id) {
                    return $vehicleData['pricing_info'] ?? ['base_amount' => 0, 'currency' => 'LKR'];
                }
            }

            return ['base_amount' => 0, 'currency' => 'LKR', 'error' => 'Pricing not available'];
        } catch (\Exception $e) {
            Log::error('Vehicle pricing calculation error', [
                'vehicle_id' => $vehicleGroup->id,
                'search_data' => $searchData,
                'error' => $e->getMessage()
            ]);
            
            return ['base_amount' => 0, 'currency' => 'LKR', 'error' => $e->getMessage()];
        }
    }

    /**
     * Update pricing via AJAX when search parameters change
     */
    public function updatePricing(Request $request, string $id)
    {
        $vehicleGroup = VehicleGroup::findOrFail($id);
        $searchData = $request->all();
        $serviceType = (string) ($searchData['service_type'] ?? 'day_rental');
        $pickupDate = $this->normalizeDateString($searchData['pickup_date'] ?? ($searchData['date'] ?? ($searchData['from_date'] ?? now()->format('Y-m-d')))) ?? now()->format('Y-m-d');
        $returnDate = $this->normalizeDateString($searchData['dropoff_date'] ?? ($searchData['return_date'] ?? ($searchData['to_date'] ?? $pickupDate))) ?? $pickupDate;

        $searchData['service_type'] = $serviceType;
        $searchData['pickup_date'] = $pickupDate;
        $searchData['return_date'] = $returnDate;
        $searchData['num_days'] = $serviceType === 'day_rental'
            ? max(1, (int) ($searchData['num_days'] ?? $this->calculateNumDays($pickupDate, $returnDate)))
            : max(1, $this->calculateNumDays($pickupDate, $returnDate));

        $pricing = $this->calculatePricing($vehicleGroup, $searchData);
        
        return response()->json([
            'success' => true,
            'pricing' => $pricing,
            'search_data' => $searchData
        ]);
    }

    private function normalizeDateString($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d');
            }

            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
                return Carbon::createFromFormat('d/m/Y', $value)->format('Y-m-d');
            }

            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function calculateNumDays(string $pickupDate, string $returnDate): int
    {
        try {
            $start = Carbon::parse($pickupDate)->startOfDay();
            $end = Carbon::parse($returnDate)->startOfDay();
            if ($end->lt($start)) {
                return 1;
            }

            return $start->diffInDays($end) + 1;
        } catch (\Exception $e) {
            return 1;
        }
    }

    private function resolveServiceTypeModel(string $serviceTypeValue): ?ServiceType
    {
        if ($serviceTypeValue === '') {
            return null;
        }

        $query = ServiceType::publicContext();
        if (Str::isUuid($serviceTypeValue)) {
            $query->where('id', $serviceTypeValue);
        } else {
            $query->where('code', $serviceTypeValue);
        }

        return $query->where('is_active', true)->first();
    }

    private function buildAvailabilityParams(array $input, $vehicleGroup, ServiceType $serviceType): array
    {
        $serviceCode = $serviceType->code;
        $pickupDate = $this->normalizeDateString($input['pickup_date'] ?? ($input['date'] ?? ($input['from_date'] ?? now()->format('Y-m-d')))) ?? now()->format('Y-m-d');
        $dropoffDate = $this->normalizeDateString($input['dropoff_date'] ?? ($input['return_date'] ?? ($input['to_date'] ?? $pickupDate))) ?? $pickupDate;
        $pickupTime = (string) ($input['pickup_time'] ?? ($input['time'] ?? ($input['from_time'] ?? '10:00')));
        $dropoffTime = (string) ($input['dropoff_time'] ?? ($input['return_time'] ?? ($input['to_time'] ?? $pickupTime)));

        $params = [
            'service_type' => $serviceType->id,
            'service_type_id' => $serviceType->id,
            'service_type_context' => 'public',
            'vehicle_group_id' => $vehicleGroup->id,
            'pickup_location' => $this->formatLocationFromInput($input, 'pickup'),
            'dropoff_location' => $this->formatLocationFromInput($input, 'dropoff'),
        ];

        if (!empty($input['package_id'])) {
            $package = ServicePackage::where('service_type_id', $serviceType->id)
                ->where('id', $input['package_id'])
                ->where('is_active', true)
                ->first();
            if ($package) {
                $params['package_id'] = $package->id;
                $params['service_package_id'] = $package->id;
                $params['package_type'] = $package->toArray();
            }
        }

        switch ($serviceCode) {
            case 'airport_transfers':
                $params['from_date'] = $pickupDate;
                $params['to_date'] = $pickupDate;
                $params['from_time'] = (string) ($input['time'] ?? $pickupTime);
                $params['to_time'] = (string) ($input['time'] ?? $dropoffTime);
                $params['transfer_type'] = (string) ($input['transfer_type'] ?? 'from-airport');

                if ($params['transfer_type'] === 'from-airport' && !empty($params['pickup_location']['address'])) {
                    $params['pickup_location']['address'] .= ' (Airport)';
                } elseif ($params['transfer_type'] === 'to-airport' && !empty($params['dropoff_location']['address'])) {
                    $params['dropoff_location']['address'] .= ' (Airport)';
                }
                break;

            case 'ride_now':
                $params['from_date'] = $pickupDate;
                $params['from_time'] = $pickupTime;

                $isReturnTrip = $this->normalizeBool($input['is_return_trip'] ?? false);
                if ($isReturnTrip) {
                    $returnDate = $this->normalizeDateString($input['return_date'] ?? ($input['return_trip_date'] ?? $pickupDate)) ?? $pickupDate;
                    $returnTime = (string) ($input['return_time'] ?? ($input['return_trip_time'] ?? '12:00'));
                    $params['is_return_trip'] = true;
                    $params['return_date'] = $returnDate;
                    $params['return_time'] = $returnTime;
                }
                break;

            case 'day_rental':
                $numDays = max(1, (int) ($input['num_days'] ?? $this->calculateNumDays($pickupDate, $dropoffDate)));
                $dayRentalDropoff = $this->normalizeDateString($input['dropoff_date'] ?? ($input['return_date'] ?? null));
                if (!$dayRentalDropoff) {
                    try {
                        $dayRentalDropoff = Carbon::parse($pickupDate)->addDays($numDays - 1)->format('Y-m-d');
                    } catch (\Exception $e) {
                        $dayRentalDropoff = $pickupDate;
                    }
                }

                $params['from_date'] = $pickupDate;
                $params['to_date'] = $dayRentalDropoff;
                $params['from_time'] = $pickupTime;
                $params['to_time'] = $dropoffTime ?: $pickupTime;
                break;

            default:
                $params['from_date'] = $pickupDate;
                $params['to_date'] = $dropoffDate;
                $params['from_time'] = $pickupTime;
                $params['to_time'] = $dropoffTime;
                break;
        }

        return $params;
    }

    private function formatLocationFromInput(array $input, string $prefix): array
    {
        $addressKeys = [$prefix, "{$prefix}_location", "{$prefix}_address"];
        $latKeys = ["{$prefix}_lat", "{$prefix}_latitude"];
        $lngKeys = ["{$prefix}_lng", "{$prefix}_longitude"];

        if ($prefix === 'pickup') {
            $addressKeys = array_merge($addressKeys, ['from', 'from_location', 'pickup_location']);
            $latKeys = array_merge($latKeys, ['from_lat', 'from_latitude']);
            $lngKeys = array_merge($lngKeys, ['from_lng', 'from_longitude']);
        } elseif ($prefix === 'dropoff') {
            $addressKeys = array_merge($addressKeys, ['to', 'to_location', 'dropoff_location']);
            $latKeys = array_merge($latKeys, ['to_lat', 'to_latitude']);
            $lngKeys = array_merge($lngKeys, ['to_lng', 'to_longitude']);
        }

        $address = '';
        foreach ($addressKeys as $key) {
            if (isset($input[$key]) && is_string($input[$key]) && trim($input[$key]) !== '') {
                $address = trim($input[$key]);
                break;
            }
        }

        $latitude = null;
        $longitude = null;
        foreach ($latKeys as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $latitude = (float) $input[$key];
                break;
            }
        }
        foreach ($lngKeys as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $longitude = (float) $input[$key];
                break;
            }
        }

        return [
            'address' => $address,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    private function normalizeBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
