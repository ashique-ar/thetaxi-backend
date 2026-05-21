<?php

namespace App\Http\Controllers;

use App\Models\Vehicle\VehicleGroup;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Services\CartService;
use App\Services\BookingFlowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;

class CartController extends Controller
{
    protected CartService $cartService;
    protected BookingFlowService $bookingFlowService;

    public function __construct(CartService $cartService, BookingFlowService $bookingFlowService)
    {
        $this->cartService = $cartService;
        $this->bookingFlowService = $bookingFlowService;
    }

    /**
     * Invalidate cart cache when cart is modified
     */
    private function invalidateCartCache(): void
    {
        $cartCacheKey = 'cart_data_' . session()->getId();
        cache()->forget($cartCacheKey);
    }

    /**
     * Display cart page
     */
    public function index()
    {
        $dbCart = $this->cartService->getOrCreateCart();

        // Migrate from session if needed
        if (empty($dbCart->items) && !empty(session()->get('cart'))) {
            $this->cartService->migrateFromSession($dbCart);
        }

        $cartItems = $dbCart->getItems();

        // Enrich cart items with distance_details if missing
        $cartItems = $this->enrichCartItemsWithDistanceDetails($cartItems);

        $totals = $dbCart->totals ?? [];

        $subtotal = $totals['subtotal'] ?? 0;
        $serviceFee = $totals['service_fee'] ?? 0;
        $tax = $totals['tax'] ?? 0;
        $discount = $totals['coupon_discount'] ?? 0;
        $total = $totals['total'] ?? 0;

        return view('cart', compact('cartItems', 'subtotal', 'serviceFee', 'tax', 'discount', 'total', 'dbCart'));
    }

    /**
     * Enrich cart items with distance_details if missing
     * This handles legacy cart items that were added before distance_details was stored
     */
    private function enrichCartItemsWithDistanceDetails($cartItems): array
    {
        $enrichedItems = [];

        foreach ($cartItems as $key => $item) {
            // If distance_details is already set and has data, skip
            if (
                !empty($item['distance_details']) &&
                (isset($item['distance_details']['allowed_total_km']) ||
                    isset($item['distance_details']['free_km_per_day']) ||
                    isset($item['distance_details']['extra_km_price']))
            ) {
                $enrichedItems[$key] = $item;
                continue;
            }

            // Try to get distance details from service package info
            $servicePackageInfo = $item['service_package_info'] ?? [];
            $serviceTypeData = $item['service_type_data'] ?? [];
            $vehicleGroupId = $item['vehicle_group_id'] ?? null;
            $serviceTypeId = $serviceTypeData['id'] ?? null;
            $days = $item['days'] ?? 1;

            $distanceDetails = [];

            // Get km limits from service package first
            if (!empty($servicePackageInfo)) {
                $maxKmPerDay = $servicePackageInfo['max_km_per_day'] ?? null;
                $maxKmPerPackage = $servicePackageInfo['max_km_per_package'] ?? null;

                if ($maxKmPerDay) {
                    $distanceDetails['free_km_per_day'] = (float) $maxKmPerDay;
                    $distanceDetails['allowed_total_km'] = (float) ($maxKmPerDay * $days);
                } elseif ($maxKmPerPackage) {
                    $distanceDetails['free_km_per_package'] = (float) $maxKmPerPackage;
                    $distanceDetails['allowed_total_km'] = (float) $maxKmPerPackage;
                }
            }

            // If no km limits from service package, try slab definition
            if (empty($distanceDetails) && $serviceTypeId) {
                $slabKmLimits = $this->cartService->getSlabKmLimits($serviceTypeId, $days);
                if ($slabKmLimits) {
                    $distanceDetails = $slabKmLimits;
                }
            }

            // Get extra km rate from common rate definitions
            if ($vehicleGroupId && $serviceTypeId) {
                $extraKmRate = $this->cartService->getExtraKmRateForVehicleGroup($vehicleGroupId, $serviceTypeId);
                if ($extraKmRate && isset($extraKmRate['rate'])) {
                    $distanceDetails['extra_km_price'] = (float) $extraKmRate['rate'];
                }
            }

            // Only set if we have some data
            if (!empty($distanceDetails)) {
                $item['distance_details'] = $distanceDetails;

                Log::debug('Enriched cart item with distance details', [
                    'cart_key' => $key,
                    'vehicle_group_id' => $vehicleGroupId,
                    'service_type_id' => $serviceTypeId,
                    'distance_details' => $distanceDetails,
                ]);
            }

            $enrichedItems[$key] = $item;
        }

        return $enrichedItems;
    }

    /**
     * Get cart data as JSON (for AJAX requests)
     * Rate limited to prevent excessive API calls
     */
    public function get()
    {
        try {
            // Rate limiting - max 1 request per second per session
            $cacheKey = 'cart_get_rate_limit_' . session()->getId();
            if (cache()->has($cacheKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many requests. Please wait a moment.',
                    'items' => [],
                    'totals' => [],
                    'count' => 0
                ], 429);
            }

            // Set rate limit cache for 1 second
            cache()->put($cacheKey, true, 1);

            // Cache cart data for 30 seconds to reduce database queries
            $cartCacheKey = 'cart_data_' . session()->getId();
            $fromCache = cache()->has($cartCacheKey);

            $cartArray = cache()->remember($cartCacheKey, 30, function () {
                $dbCart = $this->cartService->getOrCreateCart();
                return $this->cartService->toArray($dbCart);
            });

            // Log cart access for monitoring
            // Log::info('Cart API accessed', [
            //     'session_id' => session()->getId(),
            //     'from_cache' => $fromCache,
            //     'cart_items_count' => count($cartArray['items'] ?? []),
            //     'user_agent' => request()->userAgent()
            // ]);

            return response()->json([
                'success' => true,
                'items' => $cartArray['items'] ?? [],
                'totals' => $cartArray['totals'] ?? [],
                'count' => count($cartArray['items'] ?? [])
            ]);
        } catch (\Exception $e) {
            Log::error('Cart get error', [
                'error' => $e->getMessage(),
                'session_id' => session()->getId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error loading cart',
                'items' => [],
                'totals' => [],
                'count' => 0
            ], 500);
        }
    }

    /**
     * Add item to cart
     */
    public function add(Request $request)
    {
        try {
            $input = $request->all();
            $input['pickup_date'] = $this->normalizeDateInput($input['pickup_date'] ?? null);
            $input['from_date'] = $this->normalizeDateInput($input['from_date'] ?? null);
            $input['return_date'] = $this->normalizeDateInput($input['return_date'] ?? null);
            $input['to_date'] = $this->normalizeDateInput($input['to_date'] ?? null);
            $input['dropoff_date'] = $this->normalizeDateInput($input['dropoff_date'] ?? null);
            $input['date'] = $this->normalizeDateInput($input['date'] ?? null);
            $input['from_time'] = $this->normalizeTimeInput($input['from_time'] ?? null);
            $input['to_time'] = $this->normalizeTimeInput($input['to_time'] ?? null);
            $input['pickup_time'] = $this->normalizeTimeInput($input['pickup_time'] ?? null);
            $input['dropoff_time'] = $this->normalizeTimeInput($input['dropoff_time'] ?? null);
            $input['return_time'] = $this->normalizeTimeInput($input['return_time'] ?? null);
            $input['time'] = $this->normalizeTimeInput($input['time'] ?? null);
            $input['service_package_id'] = $this->normalizeTimeInput(
                $input['service_package_id'] ?? ($input['package_id'] ?? null)
            );
            $input['return_trip_date'] = $this->normalizeDateInput($input['return_trip_date'] ?? null);
            $input['return_trip_time'] = $this->normalizeTimeInput($input['return_trip_time'] ?? null);

            // Allow flexible field mapping from frontend
            $validator = Validator::make($input, [
                'vehicle_group_id' => 'sometimes|string',
                'group_id' => 'sometimes|string',
                'name' => 'sometimes|string',
                'group_name' => 'sometimes|string',
                'pickup_date' => 'sometimes|nullable|date',
                'from_date' => 'sometimes|nullable|date',
                'return_date' => 'sometimes|nullable|date',
                'to_date' => 'sometimes|nullable|date',
                'dropoff_date' => 'sometimes|nullable|date',
                'date' => 'sometimes|nullable|date',
                'from_time' => 'sometimes|nullable|string',
                'to_time' => 'sometimes|nullable|string',
                'pickup_time' => 'sometimes|nullable|string',
                'dropoff_time' => 'sometimes|nullable|string',
                'return_time' => 'sometimes|nullable|string',
                'time' => 'sometimes|nullable|string',
                'pickup' => 'sometimes|string',
                'dropoff' => 'sometimes|string',
                // Allow pickup/dropoff to be nullable or any shape (frontend may send empty string or array with address/lat/lng)
                'pickup_location' => 'sometimes|nullable',
                'pickup_lat' => 'sometimes|nullable|numeric',
                'pickup_lng' => 'sometimes|nullable|numeric',
                'dropoff_location' => 'sometimes|nullable',
                'dropoff_lat' => 'sometimes|nullable|numeric',
                'dropoff_lng' => 'sometimes|nullable|numeric',
                'search_data' => 'sometimes|array',
                'service_type' => 'sometimes|string',
                // Return trip fields
                'is_return_trip' => 'sometimes|nullable',
                'return_trip_date' => 'sometimes|nullable|date',
                'return_trip_time' => 'sometimes|nullable|string',
            ]);
            $validated = $validator->validate();

            // Map frontend field names to standard names
            $vehicleId = $validated['vehicle_group_id'] ?? $validated['group_id'] ?? null;
            $name = $validated['name'] ?? $validated['group_name'] ?? 'Vehicle Rental';
            $pickupDate = $validated['pickup_date']
                ?? $validated['from_date']
                ?? $validated['date']
                ?? ($validated['search_data']['pickup_date'] ?? $validated['search_data']['from_date'] ?? $validated['search_data']['date'] ?? null);
            $returnDate = $validated['return_date']
                ?? $validated['to_date']
                ?? $validated['dropoff_date']
                ?? ($validated['search_data']['return_date'] ?? $validated['search_data']['to_date'] ?? $validated['search_data']['dropoff_date'] ?? null);
            $fromTime = $validated['from_time']
                ?? $validated['pickup_time']
                ?? $validated['time']
                ?? ($validated['search_data']['from_time'] ?? $validated['search_data']['pickup_time'] ?? $validated['search_data']['time'] ?? '10:00');
            $toTime = $validated['to_time']
                ?? $validated['dropoff_time']
                ?? $validated['return_time']
                ?? ($validated['search_data']['to_time'] ?? $validated['search_data']['dropoff_time'] ?? $validated['search_data']['return_time'] ?? $fromTime);
            $pickupDate = $this->normalizeDateInput($pickupDate);
            $returnDate = $this->normalizeDateInput($returnDate);
            $fromTime = $this->normalizeTimeInput($fromTime) ?? '10:00';
            $toTime = $this->normalizeTimeInput($toTime) ?? $fromTime;

            // Extract location data with coordinates
            $pickupLocation = $validated['pickup_location']
                ?? $validated['pickup']
                ?? ($validated['search_data']['pickup_location'] ?? $validated['search_data']['pickup'] ?? '');
            $pickupLat = $validated['pickup_lat'] ?? ($validated['search_data']['pickup_lat'] ?? null);
            $pickupLng = $validated['pickup_lng'] ?? ($validated['search_data']['pickup_lng'] ?? null);

            $returnLocation = $validated['dropoff_location']
                ?? $validated['dropoff']
                ?? ($validated['search_data']['dropoff_location'] ?? $validated['search_data']['dropoff'] ?? $pickupLocation);
            $returnLat = $validated['dropoff_lat'] ?? ($validated['search_data']['dropoff_lat'] ?? $pickupLat);
            $returnLng = $validated['dropoff_lng'] ?? ($validated['search_data']['dropoff_lng'] ?? $pickupLng);

            // Coerce pickup/return locations to string addresses when frontend may send objects/arrays (e.g. { address, latitude, longitude })
            if (is_array($pickupLocation)) {
                $pickupLocation = $pickupLocation['address'] ?? json_encode($pickupLocation);
            }
            if (is_array($returnLocation)) {
                $returnLocation = $returnLocation['address'] ?? json_encode($returnLocation);
            }

            // Ensure we have string values (avoid validation errors when empty)
            $pickupLocation = is_null($pickupLocation) ? '' : (string) $pickupLocation;
            $returnLocation = is_null($returnLocation) ? '' : (string) $returnLocation;

            $serviceType = $validated['service_type'] ?? ($validated['search_data']['service_type'] ?? 'airport_transfers');
            $searchData = $validated['search_data'] ?? [];
            $needReturn = $searchData['need_return'] ?? $request->input('need_return');

            // Validate required fields
            if (!$vehicleId || !$pickupDate) {
                if ($request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Vehicle ID and pickup date are required'
                    ], 400);
                }
                return redirect()->back()->with('error', 'Missing required booking information.');
            }

            if ($needReturn && !$returnDate) {
                if ($request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Return date is required for this booking'
                    ], 400);
                }
                return redirect()->back()->with('error', 'Return date is required for this booking.');
            }

            if (!$returnDate) {
                $returnDate = $pickupDate;
            }

            // Extract return trip data
            $isReturnTrip = filter_var($input['is_return_trip'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $returnTripDate = $input['return_trip_date'] ?? ($searchData['return_trip_date'] ?? null);
            $returnTripTime = $input['return_trip_time'] ?? ($searchData['return_trip_time'] ?? '12:00');

            // Extract service_package_id directly from input or search_data
            $directPackageId = $input['service_package_id'] ?? $input['package_id'] ?? null;

            Log::debug('Cart add - Return trip raw data extraction', [
                'input_is_return_trip' => $input['is_return_trip'] ?? null,
                'isReturnTrip_parsed' => $isReturnTrip,
                'input_return_trip_date' => $input['return_trip_date'] ?? null,
                'searchData_return_trip_date' => $searchData['return_trip_date'] ?? null,
                'returnTripDate_final' => $returnTripDate,
                'direct_package_id' => $directPackageId,
                'searchData_package_id' => $searchData['service_package_id'] ?? $searchData['package_id'] ?? null,
            ]);

            // Get vehicle details if it exists
            $vehicleGroup = null;
            if ($vehicleId) {
                $vehicleGroup = VehicleGroup::find($vehicleId);
            }

            // Calculate days from dates - day-based calculation
            // Same date = 1 day, different dates = diffInDays + 1 (include both start and end days)
            $pickupDateObj = Carbon::parse($pickupDate);
            $returnDateObj = Carbon::parse($returnDate);
            $calculatedDays = $pickupDateObj->diffInDays($returnDateObj);
            $days = max(1, $calculatedDays + 1); // Always add 1 to include both pickup and return days

            // Recalculate pricing using BookingFlowService instead of accepting from frontend
            $totalPrice = 0;
            $perDayPrice = 0;
            $pricingInfo = null; // Store full pricing info including distance_details
            $returnTripPricing = null; // Store return trip pricing details

            Log::info(
                'Adding item to cart',
                [
                    'vehicle_id' => $vehicleId,
                    'vehicle_group_id' => $vehicleId,
                    'pickup_date' => $pickupDate,
                    'return_date' => $returnDate,
                    'service_type' => $serviceType,
                    'pickup_location' => $pickupLocation,
                    'dropoff_location' => $returnLocation,
                    'days' => $days,
                    'vehicleGroup' => $vehicleGroup,
                    'is_return_trip' => $isReturnTrip,
                    'return_trip_date' => $returnTripDate,
                ]
            );
            try {
                // Get service type
                $serviceTypeModel = ServiceType::publicContext()
                    ->where(function ($query) use ($serviceType) {
                        if (Str::isUuid($serviceType)) {
                            $query->where('id', $serviceType);
                        }

                        $query->orWhere('code', $serviceType)
                            ->orWhere('name', $serviceType);
                    })
                    ->where('is_active', true)
                    ->first();
                Log::info('Service type lookup', [
                    'service_type' => $serviceType,
                    'service_type_model' => $serviceTypeModel,
                    'vehicle_group' => $vehicleGroup
                ]);
                if ($serviceTypeModel && $vehicleGroup) {

                    // Build location arrays with coordinates
                    $pickupLocationArray = is_array($pickupLocation) ? $pickupLocation : [
                        'address' => $pickupLocation,
                        'latitude' => $pickupLat,
                        'longitude' => $pickupLng
                    ];

                    $returnLocationArray = is_array($returnLocation) ? $returnLocation : [
                        'address' => $returnLocation,
                        'latitude' => $returnLat,
                        'longitude' => $returnLng
                    ];

                    // Extract service package ID from search_data if available
                    $servicePackageIdForPricing = $searchData['service_package_id'] 
                        ?? $searchData['package_id']
                        ?? $directPackageId
                        ?? $request->input('service_package_id')
                        ?? $request->input('package_id');

                    Log::debug('Cart add - extracting service package ID', [
                        'from_search_data_service_package_id' => $searchData['service_package_id'] ?? null,
                        'from_search_data_package_id' => $searchData['package_id'] ?? null,
                        'direct_package_id' => $directPackageId,
                        'from_request' => $request->input('service_package_id') ?? $request->input('package_id') ?? null,
                        'final_package_id' => $servicePackageIdForPricing,
                        'is_return_trip' => $isReturnTrip,
                        'return_trip_date' => $returnTripDate,
                    ]);

                    $pricingParams = [
                        'service_type' => $serviceTypeModel->id,
                        'service_type_id' => $serviceTypeModel->id,
                        'service_type_context' => 'public',
                        'vehicle_groups' => [$vehicleId],
                        'from_date' => $pickupDate,
                        'from_time' => $fromTime,
                        'to_date' => $returnDate,
                        'to_time' => $toTime,
                        'pickup_location' => $pickupLocationArray,
                        'dropoff_location' => $returnLocationArray
                    ];

                    // Add service package ID to pricing params if provided (BookingFlowService expects 'package_id')
                    if ($servicePackageIdForPricing) {
                        $pricingParams['package_id'] = $servicePackageIdForPricing;
                    }

                    Log::info('Cart add pricing params', [
                        'service_package_id' => $servicePackageIdForPricing,
                        'pricing_params' => $pricingParams
                    ]);

                    // Get pricing from BookingFlowService
                    $availabilityData = $this->bookingFlowService->getAvailableVehicleGroups($pricingParams, true);

                    $availabilityData = isset($availabilityData) && isset($availabilityData['data']) ? $availabilityData['data'] : [];

                    Log::info('Pricing data from BookingFlowService', [
                        'service_package_id' => $servicePackageIdForPricing,
                        'availability_data_count' => count($availabilityData),
                        'availability_data' => $availabilityData
                    ]);

                    // Find pricing for this specific vehicle group
                    $pricingFound = false;
                    foreach ($availabilityData as $vehicleData) {
                        if ($vehicleData['id'] == $vehicleId) {
                            // Check if pricing is configured and available
                            if (!isset($vehicleData['pricing_configured']) || !$vehicleData['pricing_configured']) {
                                Log::warning('Pricing not configured for vehicle group', [
                                    'vehicle_id' => $vehicleId,
                                    'vehicle_data' => $vehicleData
                                ]);
                                break;
                            }

                            if (isset($vehicleData['pricing_info']['base_amount'])) {
                                $pricingInfo = $vehicleData['pricing_info'];
                                $oneWayPrice = (float) $pricingInfo['base_amount']; // This is TOTAL for one-way trip in LKR
                                $totalPrice = $oneWayPrice;

                                // Default per-day price based on current total (may be updated after return trip calc)
                                $perDayPrice = $days > 0 ? $totalPrice / $days : 0; // Calculate per-day in LKR

                                // Calculate return trip pricing if this is a return trip
                                if ($isReturnTrip && $returnTripDate && $servicePackageIdForPricing) {
                                    try {
                                        // Extract journey distance for KM-based return rules
                                        $journeyDistance = null;
                                        if (isset($pricingInfo['distance_details']['journey_distance'])) {
                                            $journeyDistance = (float) $pricingInfo['distance_details']['journey_distance'];
                                        }
                                        
                                        $returnTripPricing = $this->bookingFlowService->calculateReturnTripPricing([
                                            'package_id' => $servicePackageIdForPricing,
                                            'vehicle_group_id' => $vehicleId,
                                            'outbound_date' => $pickupDate,
                                            'return_date' => $returnTripDate,
                                            'one_way_fare' => $oneWayPrice,
                                            'kilometers' => $journeyDistance,
                                            'journey_distance' => $journeyDistance,
                                        ]);

                                        if ($returnTripPricing && isset($returnTripPricing['total_fare'])) {
                                            $totalPrice = (float) $returnTripPricing['total_fare'];

                                            // Recompute per-day price using final total price (important for accurate cart subtotal)
                                            $perDayPrice = $days > 0 ? $totalPrice / $days : 0;

                                            Log::info('Return trip pricing calculated for cart', [
                                                'one_way_price' => $oneWayPrice,
                                                'return_fare' => $returnTripPricing['return_fare'],
                                                'total_price' => $totalPrice,
                                                'discount_percentage' => $returnTripPricing['discount_percentage'],
                                            ]);
                                        }
                                    } catch (\Exception $e) {
                                        Log::warning('Failed to calculate return trip pricing for cart', [
                                            'error' => $e->getMessage(),
                                        ]);
                                        // Fall back to 2x one-way price
                                        $totalPrice = $oneWayPrice * 2;
                                        $perDayPrice = $days > 0 ? $totalPrice / $days : 0;
                                    }
                                }

                                Log::info('Pricing calculated for cart item', [
                                    'service_package_id' => $servicePackageIdForPricing,
                                    'total_price' => $totalPrice,
                                    'per_day_price' => $perDayPrice,
                                    'days' => $days,
                                    'is_return_trip' => $isReturnTrip,
                                    'pricing_info' => $pricingInfo
                                ]);

                                $pricingFound = true;
                                break;
                            }
                        }
                    }

                    // If pricing not found or is 0, throw error
                    if (!$pricingFound || $totalPrice <= 0) {
                        throw new \Exception('Pricing not available for this vehicle group and service type combination');
                    }
                }
            } catch (\Exception $e) {
                Log::error('Failed to calculate pricing for cart item', [
                    'vehicle_id' => $vehicleId,
                    'service_type' => $serviceType,
                    'dates' => ['from' => $pickupDate, 'to' => $returnDate],
                    'error' => $e->getMessage(),
                    'stack_trace' => $e->getTraceAsString()
                ]);

                // Return error - do NOT add item with 0 price
                if ($request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unable to calculate price for this vehicle. Please ensure pricing is configured for the selected service type and dates.'
                    ], 400);
                }
                return redirect()->back()->with('error', 'Unable to calculate price. Please try a different vehicle or date range.');
            }

            // Final validation - ensure we have valid pricing
            if ($perDayPrice <= 0 || $totalPrice <= 0) {
                Log::error('Attempted to add cart item with invalid pricing', [
                    'vehicle_id' => $vehicleId,
                    'per_day_price' => $perDayPrice,
                    'total_price' => $totalPrice
                ]);

                if ($request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid pricing. Unable to add item to cart.'
                    ], 400);
                }
                return redirect()->back()->with('error', 'Invalid pricing. Please contact support.');
            }

            // Determine if this is a package service for proper cart calculation
            $isPackageService = in_array($serviceType, ['wedding_hire', 'airport_transfers']);

            // Get service package info if provided in search_data
            $servicePackageId = $searchData['service_package_id']
                ?? $searchData['package_id']
                ?? $request->input('service_package_id')
                ?? $request->input('package_id');
            $servicePackageInfo = null;
            if ($servicePackageId) {
                $servicePackage = \App\Models\Service\ServicePackage::find($servicePackageId);
                if ($servicePackage) {
                    $servicePackageInfo = [
                        'id' => $servicePackage->id,
                        'name' => $servicePackage->name,
                        'code' => $servicePackage->code,
                        'price_multiplier' => (float) $servicePackage->price_multiplier,
                        'rate_type' => $servicePackage->rate_type,
                        'max_km_per_day' => (float) $servicePackage->max_km_per_day,
                        'max_km_per_package' => (float) $servicePackage->max_km_per_package,
                        'default_duration_hours' => (int) $servicePackage->default_duration_hours,
                    ];
                }
            }

            $cartItem = [
                'vehicle_group_id' => $vehicleId,
                'name' => $vehicleGroup?->name ?? $name,
                'vehicle_type' => $vehicleGroup?->vehicle_type ?? 'Sedan',
                'image' => $vehicleGroup?->thumbnail['path'] ?? null,
                'price' => $isPackageService ? (float) $totalPrice : (float) $perDayPrice, // Use total for packages, per-day for others
                'price_lkr' => $isPackageService ? (float) $totalPrice : (float) $perDayPrice, // Explicitly store LKR price
                'total_price' => (float) $totalPrice, // Store total price in LKR
                'total_price_lkr' => (float) $totalPrice, // Explicitly store LKR total
                'days' => (int) $days,
                'pickup_date' => $pickupDateObj->toDateString(),
                'return_date' => $returnDateObj->toDateString(),
                'from_time' => $fromTime,
                'to_time' => $toTime,
                'pickup_location' => $pickupLocation,
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'pickup_latitude' => $pickupLat,
                'pickup_longitude' => $pickupLng,
                'dropoff_location' => $returnLocation,
                'dropoff_lat' => $returnLat,
                'dropoff_lng' => $returnLng,
                'dropoff_latitude' => $returnLat,
                'dropoff_longitude' => $returnLng,
                'service_type' => $serviceType,
                'service_type_data' => $serviceTypeModel,
                'service_package_id' => $servicePackageId,
                'service_package_info' => $servicePackageInfo, // Store service package details
                'distance_details' => $pricingInfo['distance_details'] ?? null, // Store km limits and extra km rate
                // Store adjustment/discount details for frontend display
                'adjustment_details' => $pricingInfo['adjustment_details'] ?? null,
                'has_discount' => $pricingInfo['has_discount'] ?? false,
                'original_amount' => $pricingInfo['original_amount'] ?? $totalPrice,
                'original_amount_lkr' => $pricingInfo['original_amount'] ?? $totalPrice,
                'discount_amount' => $pricingInfo['discount_amount'] ?? 0,
                'discount_amount_lkr' => $pricingInfo['discount_amount'] ?? 0,
                'discount_percentage' => $pricingInfo['discount_percentage'] ?? 0,
                'savings_display' => $pricingInfo['savings_display'] ?? null,
                'search_data' => $searchData,
                'base_currency' => 'LKR', // Mark as LKR base pricing
                'is_package' => $isPackageService, // Critical: Mark package services to prevent double multiplication
                // Return trip data
                'is_return_trip' => $isReturnTrip,
                'return_trip_date' => $returnTripDate,
                'return_trip_time' => $returnTripTime,
                'return_trip_pricing' => $returnTripPricing, // Store return trip pricing breakdown
                'one_way_price' => $isReturnTrip ? ($returnTripPricing['one_way_fare'] ?? $totalPrice / 2) : null,
                'return_price' => $isReturnTrip ? ($returnTripPricing['return_fare'] ?? null) : null,
                'return_discount_percentage' => $isReturnTrip ? ($returnTripPricing['discount_percentage'] ?? 0) : null,
                'added_at' => now()
            ];

            Log::info('Cart item created with pricing', [
                'service_package_id' => $servicePackageId,
                'service_package_info' => $servicePackageInfo,
                'distance_details' => $cartItem['distance_details'],
                'price' => $cartItem['price'],
                'total_price' => $cartItem['total_price'],
                'is_package' => $cartItem['is_package'],
                'days' => $cartItem['days']
            ]);

            // Get or create cart
            $dbCart = $this->cartService->getOrCreateCart();
            // Create unique cart key - includes time and random component for multiple same vehicles added quickly
            $cartKey = 'vehicle_' . $vehicleId . '_' . time() . '_' . str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

            // Add to database cart
            $this->cartService->addItem($dbCart, $cartItem, $cartKey);

            // Invalidate cart cache
            $this->invalidateCartCache();

            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Vehicle added to cart successfully!',
                    'cart' => $this->cartService->toArray($dbCart)
                ]);
            }

            return redirect()->route('cart')->with('success', 'Vehicle added to cart successfully!');
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $e->errors()
                ], 422);
            }
            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (\Exception $e) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error adding item to cart: ' . $e->getMessage()
                ], 500);
            }
            return redirect()->back()->with('error', 'Error adding item to cart. Please try again.');
        }
    }

    /**
     * Remove item from cart (AJAX)
     */
    public function remove(Request $request)
    {
        $validated = $request->validate([
            'cart_key' => 'required|string'
        ]);

        try {
            $dbCart = $this->cartService->getOrCreateCart();

            // Check if item exists before attempting removal
            $item = $dbCart->getItem($validated['cart_key']);
            if (!$item) {
                // Log detailed debug information for investigation
                Log::warning('Cart remove requested but item not found', [
                    'requested_cart_key' => $validated['cart_key'],
                    'cart_items_keys' => array_keys($dbCart->items ?? [])
                ]);

                $response = [
                    'success' => false,
                    'message' => 'Item not found in cart'
                ];

                // Include available keys in response when app debug is enabled to aid debugging
                if (config('app.debug')) {
                    $response['available_keys'] = array_keys($dbCart->items ?? []);
                    $response['requested_key'] = $validated['cart_key'];
                }

                return response()->json($response, 404);
            }

            // Remove the item
            if ($this->cartService->removeItem($dbCart, $validated['cart_key'])) {
                // Invalidate cart cache to force fresh data on next request
                $this->invalidateCartCache();

                // Reload cart from database to ensure fresh data
                $dbCart = $this->cartService->getOrCreateCart();

                return response()->json([
                    'success' => true,
                    'message' => 'Item removed from cart successfully',
                    'cart' => $this->cartService->toArray($dbCart)
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove item from cart'
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error removing item from cart', [
                'error' => $e->getMessage(),
                'cart_key' => $validated['cart_key'] ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error removing item from cart'
            ], 500);
        }
    }

    /**
     * Clear entire cart
     */
    public function clear(Request $request)
    {
        $dbCart = $this->cartService->getOrCreateCart();
        $this->cartService->clearCart($dbCart);

        // Invalidate cart cache
        $this->invalidateCartCache();

        // Also clear session as fallback
        session()->forget(['cart', 'cart_discount', 'applied_coupon']);

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Cart cleared successfully'
            ]);
        }

        return redirect()->route('cart')->with('success', 'Cart cleared successfully.');
    }

    /**
     * Apply coupon code
     */
    public function applyCoupon(Request $request)
    {
        $validated = $request->validate([
            'coupon_code' => 'required|string|max:50'
        ]);

        $couponCode = strtoupper(trim($validated['coupon_code']));

        // Simple coupon validation (you can expand this with a coupons table)
        $validCoupons = [
            'WELCOME10' => ['discount' => 10, 'type' => 'percentage', 'description' => '10% off your first booking'],
            'SAVE50' => ['discount' => 50, 'type' => 'fixed', 'description' => '$50 off'],
            'SUMMER20' => ['discount' => 20, 'type' => 'percentage', 'description' => '20% summer discount'],
            'FIRST25' => ['discount' => 25, 'type' => 'fixed', 'description' => '$25 off first rental']
        ];

        if (!isset($validCoupons[$couponCode])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid coupon code'
            ]);
        }

        $dbCart = $this->cartService->getOrCreateCart();

        // Check if coupon already applied
        if ($dbCart->coupon_code === $couponCode) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon already applied'
            ]);
        }

        if (!$dbCart->hasItems()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart is empty'
            ]);
        }

        $coupon = $validCoupons[$couponCode];

        // Calculate subtotal
        $subtotal = $dbCart->getItems()->sum(function ($item) {
            return ($item['price'] ?? 0) * ($item['days'] ?? 1);
        });

        // Calculate discount
        if ($coupon['type'] === 'percentage') {
            $discountAmount = $subtotal * ($coupon['discount'] / 100);
        } else {
            $discountAmount = $coupon['discount'];
        }

        // Ensure discount doesn't exceed subtotal
        $discountAmount = min($discountAmount, $subtotal);

        // Apply coupon via CartService
        $this->cartService->applyCoupon($dbCart, $couponCode, $discountAmount);

        return response()->json([
            'success' => true,
            'message' => 'Coupon applied successfully! ' . $coupon['description'],
            'discount_amount' => number_format(floor(max(0, $discountAmount)), 0),
            'coupon_code' => $couponCode
        ]);
    }

    /**
     * Remove applied coupon
     */
    public function removeCoupon(Request $request)
    {
        $dbCart = $this->cartService->getOrCreateCart();
        $this->cartService->removeCoupon($dbCart);

        // Also clear session as fallback
        session()->forget(['cart_discount', 'applied_coupon']);

        return response()->json([
            'success' => true,
            'message' => 'Coupon removed successfully'
        ]);
    }

    /**
     * Get cart summary for AJAX requests
     */
    public function getSummary()
    {
        try {
            $dbCart = $this->cartService->getOrCreateCart();
            $cartTotals = $dbCart->totals ?? [];

            return response()->json([
                'success' => true,
                'cart_count' => count($dbCart->items ?? []),
                'subtotal' => number_format(floor(max(0, $cartTotals['subtotal'] ?? 0)), 0),
                'service_fee' => number_format(floor(max(0, $cartTotals['service_fee'] ?? 0)), 0),
                'addon_charges' => number_format(floor(max(0, $cartTotals['addon_charges'] ?? 0)), 0),
                'extra_km_charges' => number_format(floor(max(0, $cartTotals['extra_km_charges'] ?? 0)), 0),
                'tax' => number_format(floor(max(0, $cartTotals['tax'] ?? 0)), 0),
                'vat' => number_format(floor(max(0, $cartTotals['vat'] ?? 0)), 0),
                'discount' => number_format(floor(max(0, $cartTotals['coupon_discount'] ?? 0)), 0),
                'total' => number_format(floor(max(0, $cartTotals['total'] ?? 0)), 0),
                'applied_coupon' => session()->get('applied_coupon')
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Cart getSummary error', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error fetching cart summary'
            ], 500);
        }
    }

    /**
     * Sync cart data from frontend localStorage to server session
     * This method bridges the gap between the existing frontend cart and new backend cart
     */
    public function sync(Request $request)
    {
        $validated = $request->validate([
            'cart' => 'required|array'
        ]);

        $frontendCart = $validated['cart'];
        $backendCart = [];

        // Convert frontend cart format to backend cart format
        foreach ($frontendCart as $index => $item) {
            $cartKey = 'vehicle_' . ($item['group_id'] ?? 'unknown') . '_' . time() . '_' . $index;

            // Map frontend cart structure to backend cart structure
            $backendCart[$cartKey] = [
                'vehicle_group_id' => $item['group_id'] ?? null,
                'name' => $item['group_name'] ?? 'Vehicle Rental',
                'vehicle_type' => $item['vehicle_type'] ?? 'Sedan',
                'image' => null, // Will need to be fetched from vehicle group
                'price' => $item['base_price'] ?? 0,
                'days' => $item['duration_days'] ?? 1,
                'quantity' => $item['quantity'] ?? 1,
                'pickup_date' => $item['from_date'] ?? null,
                'return_date' => $item['to_date'] ?? null,
                'pickup_location' => $item['pickup_location'] ?? '',
                'dropoff_location' => $item['dropoff_location'] ?? '',
                'service_type' => $item['service_type'] ?? '',
                'search_id' => $item['search_id'] ?? null,
                'currency' => $item['currency'] ?? 'LKR',
                'added_at' => now()
            ];
        }

        // Store in session using the new cart key
        session()->put('cart', $backendCart);

        // Also maintain compatibility with old booking_cart key if needed
        session()->put('booking_cart', $frontendCart);

        return response()->json([
            'success' => true,
            'message' => 'Cart synced successfully',
            'cart_count' => count($backendCart)
        ]);
    }

    /**
     * Get cart item count
     */
    public function count(Request $request)
    {
        $cart = session()->get('cart', []);

        return response()->json([
            'success' => true,
            'count' => count($cart)
        ]);
    }

    /**
     * Proceed to checkout
     */
    public function checkout(Request $request)
    {
        $cart = session()->get('cart', []);

        if (empty($cart)) {
            return redirect()->route('cart')->with('error', 'Your cart is empty.');
        }

        $paymentType = $request->get('type', 'full'); // full, advance, quotation

        return redirect()->route('checkout', ['type' => $paymentType]);
    }

    /**
     * Get available addons for a service type
     */
    public function getAvailableAddons(Request $request)
    {
        try {
            $serviceType = $request->get('service_type');
            $serviceTypeId = ServiceType::publicContext()
                ->where(function ($query) use ($serviceType) {
                    $query->where('code', $serviceType)
                        ->orWhere('name', $serviceType);
                })
                ->value('id');
            $addons = $this->cartService->getAvailableAddons($serviceTypeId);

            return response()->json([
                'success' => true,
                'data' => $addons
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching available addons', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error loading addons'
            ], 500);
        }
    }

    /**
     * Get addons for a specific cart item
     */
    public function getItemAddons(Request $request, string $cartKey)
    {
        try {
            $dbCart = $this->cartService->getOrCreateCart();
            $addonsMap = $this->cartService->getItemAddons($dbCart, $cartKey);

            // Convert associative array to indexed array with addon_id key
            $addonsArray = [];
            foreach ($addonsMap as $addonId => $addonData) {
                if (is_array($addonData)) {
                    $addonsArray[] = array_merge([
                        'addon_id' => $addonId
                    ], $addonData);
                }
            }

            // Convert any stored calculated amounts from LKR to selected currency for frontend display
            foreach ($addonsArray as &$a) {
                if (isset($a['calculated_amount'])) {
                    $a['calculated_amount_lkr'] = (float) $a['calculated_amount'];
                    $a['calculated_amount'] = convertPrice((float) $a['calculated_amount']);
                    $a['currency'] = getSelectedCurrency();
                }
            }

            return response()->json([
                'success' => true,
                'data' => $addonsArray,
                'cart_key' => $cartKey
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching item addons', [
                'error' => $e->getMessage(),
                'cart_key' => $cartKey
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error loading item addons'
            ], 500);
        }
    }

    /**
     * Add addon to cart item
     */
    public function addAddon(Request $request)
    {
        try {
            $validated = $request->validate([
                'cart_key' => 'required|string',
                'addon_id' => 'required|string|uuid',
                'qty' => 'sometimes|integer|min:1'
            ]);

            $dbCart = $this->cartService->getOrCreateCart();
            $qty = $validated['qty'] ?? 1;

            $success = $this->cartService->addAddon(
                $dbCart,
                $validated['cart_key'],
                $validated['addon_id'],
                $qty
            );

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to add addon to cart'
                ], 400);
            }

            $cartArray = $this->cartService->toArray($dbCart);

            return response()->json([
                'success' => true,
                'message' => 'Addon added to cart',
                'cart' => $cartArray
            ]);
        } catch (\Exception $e) {
            Log::error('Error adding addon to cart', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error adding addon: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove addon from cart item
     */
    public function removeAddon(Request $request)
    {
        try {
            $validated = $request->validate([
                'cart_key' => 'required|string',
                'addon_id' => 'required|string|uuid'
            ]);

            $dbCart = $this->cartService->getOrCreateCart();

            $success = $this->cartService->removeAddon(
                $dbCart,
                $validated['cart_key'],
                $validated['addon_id']
            );

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to remove addon from cart'
                ], 400);
            }

            $cartArray = $this->cartService->toArray($dbCart);

            return response()->json([
                'success' => true,
                'message' => 'Addon removed from cart',
                'cart' => $cartArray
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing addon from cart', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error removing addon'
            ], 500);
        }
    }

    /**
     * Update addon quantity in cart
     */
    public function updateAddonQty(Request $request)
    {
        try {
            $validated = $request->validate([
                'cart_key' => 'required|string',
                'addon_id' => 'required|string|uuid',
                'qty' => 'required|integer|min:1'
            ]);

            $dbCart = $this->cartService->getOrCreateCart();

            $success = $this->cartService->updateAddonQty(
                $dbCart,
                $validated['cart_key'],
                $validated['addon_id'],
                $validated['qty']
            );

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update addon quantity'
                ], 400);
            }

            $cartArray = $this->cartService->toArray($dbCart);

            return response()->json([
                'success' => true,
                'message' => 'Addon quantity updated',
                'cart' => $cartArray
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating addon quantity', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error updating addon quantity'
            ], 500);
        }
    }

    /**
     * Bulk update addon quantities for multiple items
     */
    public function updateAllAddons(Request $request)
    {
        try {
            $validated = $request->validate([
                'updates' => 'required|array',
                'updates.*.cart_key' => 'required|string',
                'updates.*.addon_id' => 'required|string|uuid',
                'updates.*.qty' => 'required|integer|min:0'
            ]);

            $dbCart = $this->cartService->getOrCreateCart();

            $anyFailures = false;
            $errors = [];

            foreach ($validated['updates'] as $upd) {
                try {
                    $cartKey = $upd['cart_key'];
                    $addonId = $upd['addon_id'];
                    $qty = (int) $upd['qty'];

                    if ($qty > 0) {
                        $addonsMap = $this->cartService->getItemAddons($dbCart, $cartKey);
                        if (isset($addonsMap[$addonId])) {
                            $success = $this->cartService->updateAddonQty($dbCart, $cartKey, $addonId, $qty);
                        } else {
                            $success = $this->cartService->addAddon($dbCart, $cartKey, $addonId, $qty);
                        }
                    } else {
                        $success = $this->cartService->removeAddon($dbCart, $cartKey, $addonId);
                    }

                    if (!$success) {
                        $anyFailures = true;
                        $errors[] = "Failed to update addon {$addonId} for item {$cartKey}";
                    }
                } catch (\Exception $e) {
                    $anyFailures = true;
                    $errors[] = $e->getMessage();
                    Log::error('Error bulk updating addon', ['error' => $e->getMessage(), 'data' => $upd]);
                }
            }

            $this->invalidateCartCache();

            $cartArray = $this->cartService->toArray($dbCart);

            if ($anyFailures) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some updates failed: ' . implode('; ', array_slice($errors, 0, 3)),
                    'errors' => $errors,
                    'cart' => $cartArray
                ], 207);
            }

            return response()->json([
                'success' => true,
                'message' => 'All addons updated successfully',
                'cart' => $cartArray
            ]);
        } catch (\Exception $e) {
            Log::error('Error in updateAllAddons', ['error' => $e->getMessage(), 'data' => $request->all()]);
            return response()->json([
                'success' => false,
                'message' => 'Error updating addons: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get extra km rate for a cart item's vehicle group
     */
    public function getExtraKmRate(Request $request, string $cartKey)
    {
        try {
            $dbCart = $this->cartService->getOrCreateCart();
            $items = $dbCart->items ?? [];

            if (!isset($items[$cartKey])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart item not found'
                ], 404);
            }

            $vehicleGroupId = $items[$cartKey]['vehicle_group_id'] ?? null;
            Log::info('Getting extra km rate for cart item', [
                'cart_key' => $cartKey,
                'cart_item' => $items[$cartKey],
                'vehicle_group_id' => $vehicleGroupId
            ]);
            if (!$vehicleGroupId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicle group not found for this item'
                ], 400);
            }

            if ($items[$cartKey]['service_type_data'] && isset($items[$cartKey]['service_type_data']['id'])) {
                $serviceTypeId = $items[$cartKey]['service_type_data']['id'];
                $extraKmRate = $this->cartService->getExtraKmRateForVehicleGroup($vehicleGroupId, $serviceTypeId);
                $currentExtraKm = $this->cartService->getItemExtraKm($dbCart, $cartKey);

                // Determine if this vehicle group has slab pricing configured (slab-based pricing implies extra-km purchase availability)
                $hasSlabPricing = VehiclePricingSlabDefinition::where('service_type_id', $serviceTypeId)
                    ->where('is_active', true)
                    ->exists();

                Log::debug('Extra KM rate lookup for cart item', [
                    'cart_key' => $cartKey,
                    'vehicle_group_id' => $vehicleGroupId,
                    'service_type_id' => $serviceTypeId,
                    'extra_km_rate' => $extraKmRate,
                    'has_slab_pricing' => $hasSlabPricing,
                ]);

                // Convert extra KM rate to selected currency
                $convertedRate = null;
                if ($extraKmRate && isset($extraKmRate['rate'])) {
                    $selectedCurrency = getSelectedCurrency();
                    $convertedRateValue = convertPrice((float) $extraKmRate['rate']);
                    $convertedRate = array_merge($extraKmRate, [
                        'rate' => $convertedRateValue,
                        'original_rate_lkr' => (float) $extraKmRate['rate'],
                        'currency' => $selectedCurrency
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'rate' => $convertedRate,
                        'current_extra_km' => $currentExtraKm,
                        'vehicle_group_id' => $vehicleGroupId,
                        'service_type_id' => $serviceTypeId,
                        'has_slab' => (bool) $hasSlabPricing,
                        'service_type' => $items[$cartKey]['service_type_data']['name'] ?? null,
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Service type data missing for this cart item'
                ], 400);
            }


        } catch (\Exception $e) {
            Log::error('Error getting extra km rate', [
                'error' => $e->getMessage(),
                'cart_key' => $cartKey
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error loading extra km rate'
            ], 500);
        }
    }

    /**
     * Add or update extra km purchase for a cart item
     */
    public function addExtraKm(Request $request)
    {
        try {
            $validated = $request->validate([
                'cart_key' => 'required|string',
                'extra_km' => 'required|integer|min:0|max:10000'
            ]);

            $dbCart = $this->cartService->getOrCreateCart();
            $extraKm = (int) $validated['extra_km'];

            if ($extraKm === 0) {
                // Remove extra km if quantity is 0
                $success = $this->cartService->removeExtraKm($dbCart, $validated['cart_key']);
                $message = 'Extra km removed from cart';
            } else {
                $success = $this->cartService->addExtraKm($dbCart, $validated['cart_key'], $extraKm);
                $message = 'Extra km added to cart';
            }

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update extra km. Please ensure extra km rate is configured for this vehicle.'
                ], 400);
            }

            // Invalidate cart cache
            $this->invalidateCartCache();

            $cartArray = $this->cartService->toArray($dbCart);

            return response()->json([
                'success' => true,
                'message' => $message,
                'cart' => $cartArray
            ]);
        } catch (\Exception $e) {
            Log::error('Error adding extra km to cart', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error adding extra km: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove extra km purchase from cart item
     */
    public function removeExtraKm(Request $request)
    {
        try {
            $validated = $request->validate([
                'cart_key' => 'required|string'
            ]);

            $dbCart = $this->cartService->getOrCreateCart();

            $success = $this->cartService->removeExtraKm($dbCart, $validated['cart_key']);

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to remove extra km from cart'
                ], 400);
            }

            // Invalidate cart cache
            $this->invalidateCartCache();

            $cartArray = $this->cartService->toArray($dbCart);

            return response()->json([
                'success' => true,
                'message' => 'Extra km removed from cart',
                'cart' => $cartArray
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing extra km from cart', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error removing extra km'
            ], 500);
        }
    }

    /**
     * Apply a promo code to the cart
     * 
     * POST /cart/apply-promo-code
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function applyPromoCode(Request $request)
    {
        try {
            $validated = $request->validate([
                'promo_code' => 'required|string|max:50'
            ]);

            $promoCode = strtoupper(trim($validated['promo_code']));
            $dbCart = $this->cartService->getOrCreateCart();

            // Get customer ID if authenticated
            $customerId = null;
            if (auth()->check()) {
                $user = auth()->user();
                // Check if user has a customer profile
                $customer = \App\Models\Customer::where('user_id', $user->id)->first();
                $customerId = $customer?->id;
            }

            // Apply promo code using CartService
            $result = $this->cartService->applyPromoCode($dbCart, $promoCode, $customerId);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'error_code' => $result['error_code'] ?? 'PROMO_CODE_INVALID',
                    'message' => $result['message'],
                    'details' => $result['details'] ?? null
                ], 400);
            }

            // Invalidate cart cache
            $this->invalidateCartCache();

            $cartArray = $this->cartService->toArray($dbCart);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'discount' => $result['discount'],
                'promo_code' => $result['promo_code'],
                'cart' => $cartArray
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error applying promo code', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error applying promo code: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the applied promo code from the cart
     * 
     * POST /cart/remove-promo-code
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function removePromoCode(Request $request)
    {
        try {
            $dbCart = $this->cartService->getOrCreateCart();

            // Remove promo code using CartService
            $result = $this->cartService->removePromoCode($dbCart);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'error_code' => $result['error_code'] ?? 'NO_PROMO_CODE_APPLIED',
                    'message' => $result['message']
                ], 400);
            }

            // Invalidate cart cache
            $this->invalidateCartCache();

            $cartArray = $this->cartService->toArray($dbCart);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'removed_code' => $result['removed_code'],
                'removed_discount' => $result['removed_discount'],
                'cart' => $cartArray
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing promo code', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error removing promo code: ' . $e->getMessage()
            ], 500);
        }
    }

    private function normalizeDateInput($value): ?string
    {
        if (is_array($value)) {
            $value = $value['date'] ?? $value['value'] ?? reset($value);
        }

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
            try {
                return Carbon::createFromFormat('d/m/Y', $value)->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }

        return $value;
    }

    private function normalizeTimeInput($value): ?string
    {
        if (is_array($value)) {
            $value = $value['time'] ?? $value['value'] ?? reset($value);
        }

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
