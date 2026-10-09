<?php

namespace App\Http\Controllers;

use App\Models\Vehicle\VehicleGroup;
use App\Models\BookingSearch;
use App\Models\BookingFormTab;
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
        $searchPricingParams = null;
        
        if ($searchId) {
            $cachedSearch = data_get(session('public_vehicle_search_pricing', []), (string) $searchId, []);
            $cachedSearchParams = $cachedSearch['params'] ?? null;
            $sessionSearchParams = is_array($cachedSearchParams)
                ? $cachedSearchParams
                : session('current_search_params', []);
            $useSessionSearchParams = is_array($cachedSearchParams) || in_array((string) $searchId, array_filter([
                (string) session('session_id'),
                (string) session('booking_session_id'),
            ]), true);
            if (!$useSessionSearchParams) {
                $search = BookingSearch::find($searchId);
                $useSessionSearchParams = !$search && is_array($sessionSearchParams) && !empty($sessionSearchParams);
            }

            if ($useSessionSearchParams && is_array($sessionSearchParams) && $sessionSearchParams) {
                $serviceType = ServiceType::publicContext()->find(
                    session('backend_service_type_id') ?? ($sessionSearchParams['service_type_id'] ?? $sessionSearchParams['service_type'] ?? null)
                );
                $frontendService = session('frontend_service') ?? $serviceType?->code ?? 'day_rental';
                $search = (object) array_merge($sessionSearchParams, [
                    'id' => $searchId,
                    'service_type' => $frontendService,
                    'from_date' => $sessionSearchParams['from_date'] ?? null,
                    'to_date' => $sessionSearchParams['to_date'] ?? null,
                    'from_time' => $sessionSearchParams['from_time'] ?? null,
                    'to_time' => $sessionSearchParams['to_time'] ?? null,
                ]);
                $searchData = array_merge($sessionSearchParams, [
                    'service_type' => $frontendService,
                    'pickup_date' => $sessionSearchParams['from_date'] ?? $sessionSearchParams['pickup_date'] ?? now()->format('Y-m-d'),
                    'return_date' => $sessionSearchParams['to_date'] ?? $sessionSearchParams['return_date'] ?? null,
                    'pickup_time' => $sessionSearchParams['from_time'] ?? $sessionSearchParams['pickup_time'] ?? '10:00',
                    'return_time' => $sessionSearchParams['to_time'] ?? $sessionSearchParams['return_time'] ?? null,
                    'pickup_lat' => data_get($sessionSearchParams, 'pickup_location.latitude'),
                    'pickup_lng' => data_get($sessionSearchParams, 'pickup_location.longitude'),
                    'dropoff_lat' => data_get($sessionSearchParams, 'dropoff_location.latitude'),
                    'dropoff_lng' => data_get($sessionSearchParams, 'dropoff_location.longitude'),
                ]);
                $searchPricingParams = $sessionSearchParams;
            }

            if ($search) {
                $searchData = array_merge($searchData, [
                    'service_type' => $search->service_type ?? 'point_to_point',
                    'pickup_date' => $searchData['pickup_date'] ?? $search->from_date ?? now()->format('Y-m-d'),
                    'return_date' => $searchData['return_date'] ?? $search->to_date ?? now()->addDay()->format('Y-m-d'),
                    'pickup_time' => $search->from_time ?? '10:00',
                    'return_time' => $search->to_time ?? '18:00',
                    'pickup_location' => $searchData['pickup_location'] ?? $search->pickup_location ?? '',
                    'dropoff_location' => $searchData['dropoff_location'] ?? $search->dropoff_location ?? '',
                    'pickup_lat' => $searchData['pickup_lat'] ?? $search->pickup_lat,
                    'pickup_lng' => $searchData['pickup_lng'] ?? $search->pickup_lng,
                    'dropoff_lat' => $searchData['dropoff_lat'] ?? $search->dropoff_lat,
                    'dropoff_lng' => $searchData['dropoff_lng'] ?? $search->dropoff_lng,
                ]);
            }
        }

        // Presets may use a configured service type code or a booking tab code.
        if ($preset !== '') {
            $presetServiceType = $this->resolvePresetServiceType($preset);
        }
        if ($preset !== '' && $presetServiceType) {
            $sessionSearchParams = session('current_search_params', []);
            $sessionService = (string) session('frontend_service', '');
            $sessionServiceType = $this->resolveServiceTypeModel($sessionService);
            $sessionServiceTypeId = $sessionSearchParams['service_type_id'] ?? $sessionSearchParams['service_type'] ?? null;
            $sessionMatchesPreset = is_array($sessionSearchParams)
                && $sessionSearchParams
                && (($sessionServiceType?->id === $presetServiceType->id)
                    || ((string) $sessionServiceTypeId === (string) $presetServiceType->id));

            if ($sessionMatchesPreset) {
                $searchId = (string) session('session_id', '');
                $searchPricingParams = $sessionSearchParams;
                $searchData = array_merge($sessionSearchParams, [
                    'service_type' => $presetServiceType->code,
                    'pickup_date' => $sessionSearchParams['from_date'] ?? $sessionSearchParams['pickup_date'] ?? now()->format('Y-m-d'),
                    'return_date' => $sessionSearchParams['to_date'] ?? $sessionSearchParams['return_date'] ?? null,
                    'pickup_time' => $sessionSearchParams['from_time'] ?? $sessionSearchParams['pickup_time'] ?? '10:00',
                    'return_time' => $sessionSearchParams['to_time'] ?? $sessionSearchParams['return_time'] ?? null,
                    'pickup_lat' => data_get($sessionSearchParams, 'pickup_location.latitude'),
                    'pickup_lng' => data_get($sessionSearchParams, 'pickup_location.longitude'),
                    'dropoff_lat' => data_get($sessionSearchParams, 'dropoff_location.latitude'),
                    'dropoff_lng' => data_get($sessionSearchParams, 'dropoff_location.longitude'),
                ]);
            } else {
                $presetDays = $preset === 'monthly' ? 30 : 1;
                $pickupDate = Carbon::today();
                $returnDate = $pickupDate->copy()->addDays($presetDays - 1);

                $searchData = [
                    'service_type' => $presetServiceType->code,
                    'pickup_date' => $pickupDate->format('Y-m-d'),
                    'return_date' => $returnDate->format('Y-m-d'),
                    'date' => $pickupDate->format('Y-m-d'),
                    'num_days' => $presetDays,
                ];
                $searchPricingParams = null;
            }
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
                'date' => now()->format('Y-m-d'),
                'num_days' => 1,
            ];
        }

        if (!$search && !$searchPricingParams) {
            $serviceType = $this->resolveServiceTypeModel((string) ($searchData['service_type'] ?? ''));
            if ($serviceType) {
                $searchData = $this->applyConfiguredFormDefaults($searchData, $serviceType);
            }
        }

        // Get available service types
        $serviceTypes = ServiceType::publicContext()
            ->whereNull('deleted_at')
            ->select('id', 'code', 'name', 'description')
            ->get();

        // Calculate initial pricing based on search data
        if ($searchPricingParams) {
            $cachedSearchPricing = data_get(
                session('public_vehicle_search_pricing', []),
                $searchId . '.prices.' . $vehicleGroup->id
            );
            if (is_array($cachedSearchPricing)) {
                $pricing = $cachedSearchPricing;
            } else {
                $searchPricingParams['vehicle_group_id'] = $vehicleGroup->id;
                $searchPricingParams['page'] = 1;
                $searchPricingParams['per_page'] = 1;
                $matchedSearchVehicle = $this->bookingFlowService->getPublicVehicleGroupAvailability($searchPricingParams);
                $pricing = $matchedSearchVehicle['pricing_info'] ?? [
                    'base_amount' => 0,
                    'currency' => getSelectedCurrency(),
                    'error' => 'Pricing not available',
                ];
            }
        } else {
            $pricing = $this->calculatePricing($vehicleGroup, $searchData);
        }
        
        return view('vehicle-details', compact(
            'vehicleGroup',
            'searchData',
            'serviceTypes',
            'pricing',
            'search',
            'searchPricingParams',
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
                return ['base_amount' => 0, 'currency' => getSelectedCurrency(), 'error' => 'Invalid service type'];
            }

            $pricingParams = $this->buildAvailabilityParams($searchData, $vehicleGroup, $serviceType);

            // Get pricing from BookingFlowService
            $availabilityData = $this->bookingFlowService->getAvailableVehicleGroups($pricingParams, true);
            $groups = $availabilityData['data'] ?? [];

            foreach ($groups as $vehicleData) {
                if ($vehicleData['id'] == $vehicleGroup->id) {
                    return $vehicleData['pricing_info'] ?? ['base_amount' => 0, 'currency' => getSelectedCurrency()];
                }
            }

            return ['base_amount' => 0, 'currency' => getSelectedCurrency(), 'error' => 'Pricing not available'];
        } catch (\Exception $e) {
            Log::error('Vehicle pricing calculation error', [
                'vehicle_id' => $vehicleGroup->id,
                'search_data' => $searchData,
                'error' => $e->getMessage()
            ]);
            
            return ['base_amount' => 0, 'currency' => getSelectedCurrency(), 'error' => $e->getMessage()];
        }
    }

    /**
     * Update pricing via AJAX when search parameters change
     */
    public function updatePricing(Request $request, string $id)
    {
        $vehicleGroup = VehicleGroup::findOrFail($id);
        $searchData = $request->all();
        $serviceTypeValue = trim((string) ($searchData['service_type_id'] ?? ''));
        if ($serviceTypeValue === '') {
            $serviceTypeValue = trim((string) ($searchData['service_type'] ?? ''));
        }
        $serviceTypeModel = $this->resolveServiceTypeModel($serviceTypeValue);
        if (!$serviceTypeModel) {
            return response()->json([
                'success' => false,
                'message' => 'Select a valid service before checking the price.',
                'errors' => ['service_type' => ['The selected service is unavailable.']],
            ], 422);
        }

        $dateFields = [
            'pickup_date' => $searchData['pickup_date'] ?? ($searchData['date'] ?? ($searchData['from_date'] ?? null)),
            'return_date' => $searchData['dropoff_date'] ?? ($searchData['return_date'] ?? ($searchData['to_date'] ?? null)),
        ];
        $normalizedDates = [];
        foreach ($dateFields as $field => $value) {
            if ($value === null || trim((string) $value) === '') {
                continue;
            }
            $normalized = $this->normalizeDateString($value);
            if (!$normalized) {
                return response()->json([
                    'success' => false,
                    'message' => 'Check the selected travel dates and try again.',
                    'errors' => [$field => ['Enter a valid travel date.']],
                ], 422);
            }
            $normalizedDates[$field] = $normalized;
        }

        $pickupDate = $normalizedDates['pickup_date'] ?? now()->format('Y-m-d');
        $returnDate = $normalizedDates['return_date'] ?? $pickupDate;
        if ($returnDate < $pickupDate) {
            return response()->json([
                'success' => false,
                'message' => 'The return date must be on or after the pickup date.',
                'errors' => ['return_date' => ['The return date must be on or after the pickup date.']],
            ], 422);
        }

        $serviceType = $serviceTypeModel->code;

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
        $serviceType = null;
        if ($serviceTypeValue !== '') {
            $query = ServiceType::publicContext();
            if (Str::isUuid($serviceTypeValue)) {
                $query->where('id', $serviceTypeValue);
            } else {
                $query->where('code', $serviceTypeValue);
            }

            $serviceType = $query->where('is_active', true)->first();
        }
        if ($serviceType) {
            return $serviceType;
        }

        $tab = $serviceTypeValue !== ''
            ? BookingFormTab::query()
                ->where('code', $serviceTypeValue)
                ->where('enabled', true)
                ->first()
            : null;

        if ($tab?->service_type_code) {
            $mappedServiceType = ServiceType::publicContext()
                ->where('code', $tab->service_type_code)
                ->where('is_active', true)
                ->first();
            if ($mappedServiceType) {
                return $mappedServiceType;
            }
        }

        $defaultServiceType = (string) $this->websiteSettingsService->get('default_service_type', 'day_rental');
        $defaultQuery = ServiceType::publicContext()->where('is_active', true);
        if (Str::isUuid($defaultServiceType)) {
            $defaultQuery->where('id', $defaultServiceType);
        } else {
            $defaultQuery->where('code', $defaultServiceType);
        }

        return $defaultQuery->first();
    }

    private function resolvePresetServiceType(string $preset): ?ServiceType
    {
        if (in_array($preset, ['daily', 'monthly'], true)) {
            $rentalTabCodes = BookingFormTab::query()
                ->where('enabled', true)
                ->whereHas('serviceType', fn ($query) => $query
                    ->where('is_active', true)
                    ->where('pricing_mode', 'day'))
                ->orderBy('sort_order')
                ->pluck('service_type_code');

            foreach ($rentalTabCodes as $serviceTypeCode) {
                $serviceType = ServiceType::publicContext()
                    ->where('is_active', true)
                    ->where('pricing_mode', 'day')
                    ->where('code', $serviceTypeCode)
                    ->first();

                if ($serviceType) {
                    return $serviceType;
                }
            }

            return ServiceType::publicContext()
                ->where('is_active', true)
                ->where('pricing_mode', 'day')
                ->orderBy('priority')
                ->first();
        }

        $serviceType = ServiceType::publicContext()
            ->where('is_active', true)
            ->where('code', $preset)
            ->first();

        if ($serviceType) {
            return $serviceType;
        }

        $tab = BookingFormTab::query()
            ->where('enabled', true)
            ->where('code', $preset)
            ->first();

        return $tab?->service_type_code
            ? ServiceType::publicContext()
                ->where('is_active', true)
                ->where('code', $tab->service_type_code)
                ->first()
            : null;
    }

    private function applyConfiguredFormDefaults(array $searchData, ServiceType $serviceType): array
    {
        $formConfig = app(\App\Services\DynamicServiceConfigurationService::class)
            ->getServiceFormConfiguration($serviceType->code);
        $fields = $formConfig['fields'] ?? [];
        $mappings = $formConfig['field_mappings'] ?? [];
        $dateMappings = data_get($mappings, 'dates', []);
        $locationMappings = data_get($mappings, 'locations', []);
        $pickupLocationConfigured = false;
        $dropoffLocationConfigured = false;

        foreach ($fields as $fieldName => $field) {
            if (!is_array($field) || !array_key_exists('default', $field) || $field['default'] === '') {
                continue;
            }

            $submitAs = (string) ($field['submit_as'] ?? $fieldName);
            $default = $field['default'];
            $type = $field['type'] ?? '';
            $searchData[$submitAs] = $default;

            if ($type === 'location') {
                $isPickup = str_contains((string) $fieldName, 'pickup')
                    || in_array($submitAs, ['pickup', 'pickup_location', $locationMappings['pickup_location'] ?? null], true)
                    || ($locationMappings['pickup_location'] ?? null) === $fieldName;
                $isDropoff = str_contains((string) $fieldName, 'dropoff')
                    || in_array($submitAs, ['dropoff', 'dropoff_location', $locationMappings['dropoff_location'] ?? null], true)
                    || ($locationMappings['dropoff_location'] ?? null) === $fieldName;

                if ($isPickup) {
                    $searchData['pickup_location'] = $default;
                    $searchData['pickup_lat'] = $field['default_lat'] ?? $searchData['pickup_lat'] ?? null;
                    $searchData['pickup_lng'] = $field['default_lng'] ?? $searchData['pickup_lng'] ?? null;
                    $pickupLocationConfigured = true;
                } elseif ($isDropoff) {
                    $searchData['dropoff_location'] = $default;
                    $searchData['dropoff_lat'] = $field['default_lat'] ?? $searchData['dropoff_lat'] ?? null;
                    $searchData['dropoff_lng'] = $field['default_lng'] ?? $searchData['dropoff_lng'] ?? null;
                    $dropoffLocationConfigured = true;
                }
            } elseif ($type === 'date') {
                $isDropoff = str_contains((string) $fieldName, 'dropoff')
                    || str_contains((string) $fieldName, 'return')
                    || in_array($submitAs, [$dateMappings['to_date'] ?? null, 'to_date', 'return_date', 'dropoff_date'], true)
                    || ($dateMappings['to_date'] ?? null) === $fieldName;
                $date = $this->resolveConfiguredDate($default);
                if ($date) {
                    $searchData[$isDropoff ? 'return_date' : 'pickup_date'] = $date;
                }
            } elseif ($type === 'time') {
                $isDropoff = str_contains((string) $fieldName, 'dropoff')
                    || str_contains((string) $fieldName, 'return')
                    || in_array($submitAs, [$dateMappings['to_time'] ?? null, 'to_time', 'return_time', 'dropoff_time'], true)
                    || ($dateMappings['to_time'] ?? null) === $fieldName;
                $searchData[$isDropoff ? 'return_time' : 'pickup_time'] = $default;
            }
        }

        if ($pickupLocationConfigured && !$dropoffLocationConfigured) {
            $searchData['dropoff_location'] = $searchData['pickup_location'];
            $searchData['dropoff_lat'] = $searchData['pickup_lat'] ?? null;
            $searchData['dropoff_lng'] = $searchData['pickup_lng'] ?? null;
        }

        return $searchData;
    }

    private function resolveConfiguredDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (in_array(strtolower($value), ['today', 'tomorrow'], true)) {
            return Carbon::today()->addDays(strtolower($value) === 'tomorrow' ? 1 : 0)->format('Y-m-d');
        }
        if (preg_match('/^\+(\d+)\s*days?$/i', $value, $matches)) {
            return Carbon::today()->addDays((int) $matches[1])->format('Y-m-d');
        }

        return $this->normalizeDateString($value);
    }

    private function buildAvailabilityParams(array $input, $vehicleGroup, ServiceType $serviceType): array
    {
        $serviceCode = $serviceType->code;
        $pickupDate = $this->normalizeDateString($input['pickup_date'] ?? ($input['date'] ?? ($input['from_date'] ?? now()->format('Y-m-d')))) ?? now()->format('Y-m-d');
        $dropoffDate = $this->normalizeDateString($input['dropoff_date'] ?? ($input['return_date'] ?? ($input['to_date'] ?? $pickupDate))) ?? $pickupDate;
        $pickupTime = (string) ($input['pickup_time'] ?? ($input['time'] ?? ($input['from_time'] ?? '10:00')));
        $dropoffTime = (string) ($input['dropoff_time'] ?? ($input['return_time'] ?? ($input['to_time'] ?? $pickupTime)));

        $params = array_intersect_key($input, array_flip([
            'additional_pickup_locations',
            'additional_dropoff_locations',
            'ordered_additional_stops',
            'transfer_type',
            'is_return_trip',
            'return_trip_date',
            'return_trip_time',
            'package_type',
            'package_hours',
            'contract_type',
            'rental_mode',
            'passengers',
            'currency',
            'service_type_id',
        ]));
        $params['package_type'] = $params['package_type'] ?? 'multi-day';
        $params = array_merge($params, [
            'service_type' => $serviceType->id,
            'service_type_id' => $serviceType->id,
            'service_type_context' => 'public',
            'vehicle_group_id' => $vehicleGroup->id,
            'pickup_location' => $this->formatLocationFromInput($input, 'pickup'),
            'dropoff_location' => $this->formatLocationFromInput($input, 'dropoff'),
        ]);

        $packageId = $input['package_id'] ?? $input['service_package_id'] ?? null;
        if (!empty($packageId)) {
            $package = ServicePackage::where('service_type_id', $serviceType->id)
                ->where('id', $packageId)
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
