<?php

namespace App\Http\Controllers;

use App\Services\BookingFlowService;
use App\Services\CurrencyService;
use App\Services\DiscountService;
use App\Services\MailDispatchService;
use App\Models\BookingSearch;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Service\ServiceType;
use App\Http\Requests\BookingSearchRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Service\ServicePackage;

class BookingController extends Controller
{
    protected BookingFlowService $bookingFlowService;
    protected CurrencyService $currencyService;
    protected DiscountService $discountService;
    protected MailDispatchService $mailDispatchService;

    public function __construct(
        BookingFlowService $bookingFlowService,
        CurrencyService $currencyService,
        DiscountService $discountService,
        MailDispatchService $mailDispatchService
    ) {
        $this->bookingFlowService = $bookingFlowService;
        $this->currencyService = $currencyService;
        $this->discountService = $discountService;
        $this->mailDispatchService = $mailDispatchService;
    }

    /**
     * Handle booking search request
     * Maps frontend service type code to ServiceType and calls BookingFlowService
     */
    public function search(BookingSearchRequest $request)
    {
        try {
            // Get or create session ID for this search
            $sessionId = $this->getOrCreateSessionId();

            // Map frontend service type code to backend ServiceType
            $frontendService = $request->input('service_type');
            $serviceType = $this->resolveServiceType($frontendService);

            if (!$serviceType) {
                Log::warning('Service type not found for frontend service', [
                    'frontend_service' => $frontendService,
                    'request_data' => $request->all()
                ]);

                return redirect()->back()
                    ->withInput()
                    ->with('error', 'Service type not configured. Please contact support.');
            }

            // Transform frontend request data to BookingFlowService format
            $searchParams = $this->transformSearchParams($request->all(), $serviceType);
            $searchParams['package_type'] = $request->input('package_id') ? $serviceType->packages()->find($request->input('package_id'))?->toArray() : null;
            $searchParams['service_package_id'] = $searchParams['package_type'] ? $searchParams['package_type']['id'] : null;
            // Store search params and context in session for results page
            session()->put('current_search_params', $searchParams);
            session()->put('search_timestamp', now());
            session()->put('session_id', $sessionId);
            session()->put('backend_service_type_id', $serviceType->id);
            session()->put('frontend_service', $frontendService);

            // Redirect to search results page
            return redirect()->route('search')
                ->with('success', 'Search completed! Here are the available vehicles for your journey.');

        } catch (\Exception $e) {
            Log::error('Error in booking search', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'An error occurred while processing your search. Please try again.');
        }
    }

    /**
     * Resolve frontend service type code to ServiceType model
     * Maps frontend codes: airport_transfers, point_to_point, ride_now, wedding_hire, corporate
     */
    protected function resolveServiceType(string $code): ?ServiceType
    {
        // dd(ServiceType::get()->toArray());
        return ServiceType::where('code', $code)
            ->where('is_active', true)
            ->first();
    }


    /**
     * Transform frontend search parameters to BookingFlowService format
     * Handles field mapping for different service types
     */
    protected function transformSearchParams(array $requestData, ServiceType $serviceType): array
    {
        $params = [
            'service_type' => $serviceType->id,
            'service_type_id' => $serviceType->id,
            'page' => 1,
            'per_page' => 50,
        ];

        // Handle different frontend service types by code
        $code = $serviceType->code;

        switch ($code) {
            case 'airport_transfers':
                $params['from_date'] = Carbon::parse($requestData['date'])->format('Y-m-d');
                $params['to_date'] = Carbon::parse($requestData['date'])->format('Y-m-d');
                $params['from_time'] = $requestData['time'] ?? '00:00';
                $params['to_time'] = $requestData['time'] ?? '00:00';
                $params['pickup_location'] = $this->formatLocation($requestData, 'pickup');
                $params['dropoff_location'] = $this->formatLocation($requestData, 'dropoff');

                // Log mapping for airport transfers to help debug address field mismatches
                Log::info('Airport search payload mapping', [
                    'from' => $requestData['from'] ?? null,
                    'to' => $requestData['to'] ?? null,
                    'pickup' => $requestData['pickup'] ?? null,
                    'dropoff' => $requestData['dropoff'] ?? null,
                    'pickup_lat' => $requestData['pickup_lat'] ?? null,
                    'dropoff_lat' => $requestData['dropoff_lat'] ?? null,
                ]);

                break;

            case 'point_to_point':
                $fromDate = Carbon::parse($requestData['date']);
                // Default to same date if no return date provided (1 day booking)
                $toDate = isset($requestData['return_date'])
                    ? Carbon::parse($requestData['return_date'])
                    : $fromDate->copy();

                $params['from_date'] = $fromDate->format('Y-m-d');
                $params['to_date'] = $toDate->format('Y-m-d');
                $params['from_time'] = $requestData['time'] ?? '00:00';
                $params['to_time'] = $requestData['return_time'] ?? $requestData['time'] ?? '00:00';
                $params['pickup_location'] = $this->formatLocation($requestData, 'pickup');
                $params['dropoff_location'] = $this->formatLocation($requestData, 'dropoff');
                break;

            case 'ride_now':
                $pickupDate = Carbon::parse($requestData['pickup_date'] ?? $requestData['date']);
                $dropoffDate = isset($requestData['dropoff_date'])
                    ? Carbon::parse($requestData['dropoff_date'])
                    : $pickupDate->copy();

                $params['from_date'] = $pickupDate->format('Y-m-d');
                // $params['to_date'] = $dropoffDate->format('Y-m-d');
                $params['from_time'] = $requestData['pickup_time'] ?? '00:00';
                // $params['to_time'] = $requestData['dropoff_time'] ?? '00:00';
                $params['pickup_location'] = $this->formatLocation($requestData, 'pickup');
                $params['dropoff_location'] = $this->formatLocation($requestData, 'dropoff');
                $params['package_type'] = $requestData['package_type'] ?? 'multi-day';
                break;
            case 'day_rental':
                $pickupDate = Carbon::parse($requestData['pickup_date'] ?? $requestData['date']);
                $dropoffDate = isset($requestData['dropoff_date'])
                    ? Carbon::parse($requestData['dropoff_date'])
                    : $pickupDate->copy();

                $params['from_date'] = $pickupDate->format('Y-m-d');
                $params['to_date'] = $dropoffDate->format('Y-m-d');
                $params['from_time'] = $requestData['pickup_time'] ?? '00:00';
                $params['to_time'] = $requestData['dropoff_time'] ?? '00:00';
                $params['pickup_location'] = $this->formatLocation($requestData, 'pickup');
                $params['dropoff_location'] = $this->formatLocation($requestData, 'dropoff');
                $params['package_type'] = $requestData['package_type'] ?? 'multi-day';
                break;

            case 'wedding_hire':
                $params['from_date'] = Carbon::parse($requestData['date'])->format('Y-m-d');
                $params['to_date'] = Carbon::parse($requestData['date'])->format('Y-m-d');
                $params['from_time'] = $requestData['time'] ?? '00:00';
                $params['to_time'] = $requestData['time'] ?? '23:59';
                $params['pickup_location'] = $this->formatLocation($requestData, 'pickup');
                $params['dropoff_location'] = $this->formatLocation($requestData, 'dropoff');
                $params['package_hours'] = $requestData['package_hours'] ?? 6;
                break;

            case 'corporate':
                $params['from_date'] = Carbon::parse($requestData['date'])->format('Y-m-d');
                $params['to_date'] = Carbon::parse($requestData['date'])->format('Y-m-d');
                $params['from_time'] = $requestData['time'] ?? '00:00';
                $params['to_time'] = $requestData['time'] ?? '23:59';
                $params['pickup_location'] = $this->formatLocation($requestData, 'pickup');
                $params['contract_type'] = $requestData['contract_type'] ?? 'weekly';
                break;
        }

        // Add common parameters
        $params['passengers'] = (int) ($requestData['passengers'] ?? 1);

        // Add ServicePackage support if provided
        if (!empty($requestData['package_id'])) {
            $params['package_id'] = $requestData['package_id'];

            Log::info('ServicePackage selected in booking search', [
                'service_type' => $serviceType->code,
                'package_id' => $requestData['package_id'],
            ]);
        }

        return $params;
    }

    /**
     * Format location data for BookingFlowService
     */
    protected function formatLocation(array $data, string $prefix): array
    {
        // Support multiple possible field names coming from various frontend forms
        // Primary keys: prefix (pickup/dropoff)
        $addressKeys = [
            $prefix,
            "{$prefix}_location",
            "{$prefix}_address",
        ];

        // Alternative keys used by airport transfers form: 'from' and 'to'
        $alt = null;
        if ($prefix === 'pickup') {
            $alt = 'from';
        } elseif ($prefix === 'dropoff') {
            $alt = 'to';
        }

        if ($alt) {
            $addressKeys[] = $alt;
            $addressKeys[] = "{$alt}_location";
            $addressKeys[] = "{$alt}_address";
        }

        $address = '';
        foreach ($addressKeys as $k) {
            if (isset($data[$k]) && $data[$k] !== '') {
                $address = $data[$k];
                break;
            }
        }

        // Support multiple latitude/longitude field name conventions
        $latKeys = ["{$prefix}_lat", "{$prefix}_latitude"];
        $lngKeys = ["{$prefix}_lng", "{$prefix}_longitude"];
        if ($alt) {
            $latKeys[] = "{$alt}_lat";
            $latKeys[] = "{$alt}_latitude";
            $lngKeys[] = "{$alt}_lng";
            $lngKeys[] = "{$alt}_longitude";
        }

        $latitude = null;
        $longitude = null;
        foreach ($latKeys as $k) {
            if (isset($data[$k]) && $data[$k] !== '') {
                $latitude = $data[$k];
                break;
            }
        }
        foreach ($lngKeys as $k) {
            if (isset($data[$k]) && $data[$k] !== '') {
                $longitude = $data[$k];
                break;
            }
        }

        $location = [
            'address' => $address,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        // Ensure numeric values and validate coordinates
        if ($location['latitude']) {
            $latitude = (float) $location['latitude'];
            // Validate latitude range (-90 to 90)
            if ($latitude >= -90 && $latitude <= 90) {
                $location['latitude'] = $latitude;
            } else {
                Log::warning("Invalid latitude value", ['latitude' => $latitude, 'prefix' => $prefix]);
                $location['latitude'] = null;
            }
        }

        if ($location['longitude']) {
            $longitude = (float) $location['longitude'];
            // Validate longitude range (-180 to 180)
            if ($longitude >= -180 && $longitude <= 180) {
                $location['longitude'] = $longitude;
            } else {
                Log::warning("Invalid longitude value", ['longitude' => $longitude, 'prefix' => $prefix]);
                $location['longitude'] = null;
            }
        }

        // Do not synthesize or guess an address from coordinates here; prefer the raw address from the request or initial data.
        // If address is missing, leave it null/empty so the client can display exactly what was provided.

        return $location;
    }

    /**
     * Show search results page using BookingFlowService
     * Now calls the same service as the API for consistency
     */
    public function showResults(Request $request, ?string $id = null)
    {
        try {
            // Get search parameters from session
            $searchParams = session()->get('current_search_params');
            $searchTimestamp = session()->get('search_timestamp');
            $pricingContext = session()->get('pricing_context');
            $frontendService = session()->get('frontend_service');

            if (!$searchParams) {
                return redirect()->route('home')
                    ->with('error', 'No search data found. Please start a new search.');
            }

            // Check if search is expired (configurable in hours; 0 = disabled)
            $expiryHours = config('booking.search_expiry_hours', 0);
            if ($expiryHours > 0 && $searchTimestamp && Carbon::parse($searchTimestamp)->diffInHours(now()) > $expiryHours) {
                return redirect()->route('home')
                    ->with('warning', 'Your search has expired. Please start a new search for updated prices.');
            }

            $availabilityData = $this->bookingFlowService->getAvailableVehicleGroups($searchParams, true);

            // Extract data and pagination
            $vehicleGroups = $availabilityData['data'] ?? $availabilityData;
            $pagination = $availabilityData['pagination'] ?? null;
            $totalJourneyDistance = $availabilityData['total_journey_distance_km'] ?? null;
            $totalJourneyDuration = $availabilityData['total_journey_duration_seconds'] ?? null;

            // Ensure vehicleGroups is always an array
            $vehicleGroups = $vehicleGroups ?? [];

            // Transform results for view (add public-specific enhancements)
            $transformedData = $this->transformResultsForPublicView($vehicleGroups, $searchParams, $pricingContext);

            // Wrap results in expected structure for blade template
            $results = [
                'data' => $transformedData,
                'total' => count($transformedData),
                'pagination' => $pagination
            ];

            // Prepare search object for view compatibility (include ID for blade template)
            // Map all search params to individual properties for form binding
            $search = (object) array_merge(
                [
                    'id' => session('session_id'), // Add ID for blade compatibility
                    'search_params' => $searchParams,
                    'pricing_context' => $pricingContext,
                    'frontend_service' => $frontendService,
                    'service_type' => $frontendService, // Form needs this
                    'created_at' => $searchTimestamp,
                    'total_distance_km' => $totalJourneyDistance, // Add distance information
                    'total_duration_seconds' => $totalJourneyDuration, // Add duration information
                ],
                // Flatten search_params so form fields can access properties
                [
                    'from_date' => $searchParams['from_date'] ?? null,
                    'to_date' => $searchParams['to_date'] ?? null,
                    'from_time' => $searchParams['from_time'] ?? null,
                    'to_time' => $searchParams['to_time'] ?? null,
                    'pickup_location' => is_array($searchParams['pickup_location'] ?? null)
                        ? $searchParams['pickup_location']['address'] ?? ''
                        : $searchParams['pickup_location'] ?? '',
                    'pickup_latitude' => is_array($searchParams['pickup_location'] ?? null)
                        ? $searchParams['pickup_location']['latitude'] ?? null
                        : null,
                    'pickup_longitude' => is_array($searchParams['pickup_location'] ?? null)
                        ? $searchParams['pickup_location']['longitude'] ?? null
                        : null,
                    'dropoff_location' => is_array($searchParams['dropoff_location'] ?? null)
                        ? $searchParams['dropoff_location']['address'] ?? ''
                        : $searchParams['dropoff_location'] ?? '',
                    'dropoff_latitude' => is_array($searchParams['dropoff_location'] ?? null)
                        ? $searchParams['dropoff_location']['latitude'] ?? null
                        : null,
                    'dropoff_longitude' => is_array($searchParams['dropoff_location'] ?? null)
                        ? $searchParams['dropoff_location']['longitude'] ?? null
                        : null,
                    'duration_days' => isset($searchParams['to_date'], $searchParams['from_date'])
                        ? max(1, Carbon::parse($searchParams['to_date'])->diffInDays(Carbon::parse($searchParams['from_date'])) + 1)
                        : 1,
                    'passengers' => $searchParams['passengers'] ?? 1,
                    'package_type' => $searchParams['package_type'] ?? null,
                    'package_hours' => $searchParams['package_hours'] ?? null,
                    'contract_type' => $searchParams['contract_type'] ?? null,
                    'service_package_id' => $searchParams['service_package_id'] ?? $searchParams['package_id'] ?? null

                ]
            );

            // Attach service package KM limits if package_type present
            $search->max_km_per_day = null;
            $search->max_km_per_package = null;
            if (!empty($searchParams['package_type'])) {
                // Normalize max_km_per_day: remove trailing .0 when fractional part is zero
                $maxKmPerDay = $searchParams['package_type']['max_km_per_day'] ?? null;
                if (is_numeric($maxKmPerDay)) {
                    $maxKmPerDay = (float) $maxKmPerDay;
                    $search->max_km_per_day = ($maxKmPerDay == (int) $maxKmPerDay) ? (int) $maxKmPerDay : $maxKmPerDay;
                } else {
                    $search->max_km_per_day = $maxKmPerDay;
                }

                // Normalize max_km_per_package: remove trailing .0 when fractional part is zero
                $maxKmPerPackage = $searchParams['package_type']['max_km_per_package'] ?? null;
                if (is_numeric($maxKmPerPackage)) {
                    $maxKmPerPackage = (float) $maxKmPerPackage;
                    $search->max_km_per_package = ($maxKmPerPackage == (int) $maxKmPerPackage) ? (int) $maxKmPerPackage : $maxKmPerPackage;
                } else {
                    $search->max_km_per_package = $maxKmPerPackage;
                }
            }
            // Get additional data for enhanced UI
            $additionalData = [
                'popular_destinations' => $this->getPopularDestinations(),
                'active_promotions' => $this->getActivePromotionalOffers($search),
                'pagination' => $pagination,
            ];

            return view('search', array_merge(compact('search', 'results'), $additionalData));

        } catch (\Exception $e) {
            Log::error('Error displaying search results', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->route('home')
                ->with('error', 'An error occurred while loading search results. Please try again.');
        }
    }

    /**
     * Transform BookingFlowService results for public view
     * Adds customer-facing enhancements and formatting
     */
    protected function transformResultsForPublicView(array $vehicleGroups, array $searchParams, $pricingContext): array
    {
        $results = [];

        // Ensure vehicleGroups is an array
        $vehicleGroups = $vehicleGroups ?? [];

        foreach ($vehicleGroups as $index => $groupData) {
            // Check if we have minimum required data
            if (!isset($groupData['id']) || !isset($groupData['name'])) {
                Log::warning("Skipping vehicle group {$index} - missing required data");
                continue;
            }

            // Format pricing from the structure returned by BookingFlowService
            $pricingInfo = $groupData['pricing_info'] ?? [];

            // Get service type information
            $serviceType = null;
            if (isset($searchParams['service_type'])) {
                $serviceTypeId = $searchParams['service_type'];
                $serviceTypeModel = ServiceType::find($serviceTypeId);
                $serviceType = $serviceTypeModel ? $serviceTypeModel->code : 'point_to_point';
            }

            // Convert distance_details pricing to selected currency
            $distanceDetails = $pricingInfo['distance_details'] ?? null;
            if ($distanceDetails && isset($distanceDetails['extra_km_price'])) {
                $selectedCurrency = $this->currencyService->getSelectedCurrency();
                $distanceDetails['extra_km_price'] = $this->currencyService->convertFromLKR(
                    (float) $distanceDetails['extra_km_price'],
                    $selectedCurrency
                );
            }

            $formattedPricing = !empty($pricingInfo) ? [
                'base_amount' => $pricingInfo['base_amount'] ?? 0,
                'total_amount' => $pricingInfo['total_amount'] ?? $pricingInfo['base_amount'] ?? 0,
                'currency' => $pricingInfo['currency'] ?? 'LKR',
                'breakdown' => $pricingInfo['breakdown'] ?? [],
                'distance_details' => $distanceDetails,
                'duration_info' => array_merge($pricingInfo['duration_info'] ?? [], [
                    'package_hours' => $searchParams['package_hours'] ?? null
                ]),
                'service_type' => $serviceType,
            ] : [];

            // Log pricing info for debugging
            Log::debug('TransformResultsForPublicView - Pricing formatted', [
                'vehicle_group_id' => $groupData['id'],
                'pricing_info_has_distance_details' => isset($pricingInfo['distance_details']),
                'distance_details' => $pricingInfo['distance_details'] ?? null,
                'base_amount' => $formattedPricing['base_amount'] ?? 0,
            ]);

            // Build result using the ACTUAL structure from BookingFlowService
            $results[] = [
                // Direct mapping from BookingFlowService response
                'id' => $groupData['id'],
                'name' => $groupData['name'],
                'description' => $groupData['description'] ?? '',
                'seating_capacity' => $groupData['seating_capacity'] ?? null,
                'passengers_count' => $groupData['passengers_count'] ?? null,
                'no_of_doors' => $groupData['no_of_doors'] ?? null,
                'air_conditioning' => $groupData['air_conditioning'] ?? null,
                'refundable_deposit' => $groupData['refundable_deposit'] ?? null,
                'hand_luggages' => $groupData['hand_luggages'] ?? null,
                'category' => [
                    'name' => $groupData['category'] ?? null,
                ],
                'transmission' => [
                    'name' => $groupData['features']['transmission'] ?? null,
                ],
                'fuel_type' => [
                    'name' => $groupData['features']['fuel_type'] ?? null,
                ],
                // Pricing and availability
                'pricing_info' => $formattedPricing,
                'enhanced_pricing' => [],
                'available_count' => $groupData['available_count'] ?? 0,
                'total_count' => $groupData['total_count'] ?? 0,
                'thumbnail' => $groupData['thumbnail'] ?? null,
                'recommended' => false, // Can be enhanced later
                'service_features' => $this->getServiceFeatures($serviceType ?? 'airport_transfers'),
                'savings_info' => [],
                'payment_options' => $this->getAvailablePaymentOptions($formattedPricing),
            ];
        }
        return $results;
    }

    /**
     * Format pricing data for public display
     */
    protected function formatPricingForPublic(array $pricing): array
    {
        if (empty($pricing)) {
            return [];
        }

        return [
            'base_amount' => $pricing['base_pricing']['total_amount'] ?? 0,
            'total_amount' => $pricing['summary']['total_amount'] ?? 0,
            'currency' => $pricing['currency'] ?? 'LKR',
            'duration' => $pricing['duration'] ?? [],
            'breakdown' => $this->getPublicPriceBreakdown($pricing),
            'includes' => $pricing['base_pricing']['includes'] ?? [],
        ];
    }

    // Removed getEnhancedSearchResults, calculateEnhancedPricing, and getAdditionalSearchData
    // Now using BookingFlowService directly which handles all pricing logic

    /**
     * Handle corporate enquiry submission
     */
    public function enquiry(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            'contact_person' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'requirements' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Please check your enquiry details and try again.');
        }

        try {
            // Store enquiry with enhanced tracking
            $enquiryData = array_merge($request->all(), [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'submitted_at' => now(),
                'status' => 'pending'
            ]);

            // TODO: Store in database and send notifications
            // CorporateEnquiry::create($enquiryData);
            // Mail::to(config('mail.corporate_enquiries'))->send(new CorporateEnquiryNotification($enquiryData));

            return redirect()->route('contact')
                ->with('success', 'Thank you for your enquiry! Our corporate team will contact you within 24 hours.');

        } catch (\Exception $e) {
            Log::error('Error submitting corporate enquiry', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'An error occurred while submitting your enquiry. Please try again.');
        }
    }

    /**
     * Add vehicle to cart with pricing validation
     */
    public function addToCart(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'search_id' => 'required|string|exists:booking_searches,id',
                'vehicle_group_id' => 'required|string|exists:vehicle_groups,id',
                'quantity' => 'nullable|integer|min:1|max:10',
                'selected_addons' => 'nullable|array',
                'special_requirements' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid data provided',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get search params from session
            $searchParams = session()->get('current_search_params');
            if (!$searchParams) {
                return response()->json([
                    'success' => false,
                    'message' => 'Search session expired. Please search again.'
                ], 404);
            }

            // Add to cart with pricing calculation
            $cartItem = $this->addVehicleToCart($searchParams, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Vehicle added to cart successfully',
                'cart_item' => $cartItem,
                'cart_total' => $this->getCartTotal()
            ]);

        } catch (\Exception $e) {
            Log::error('Error adding vehicle to cart', [
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while adding to cart'
            ], 500);
        }
    }

    /**
     * Get current cart contents
     */
    public function getCart()
    {
        try {
            $cart = session()->get('booking_cart', []);
            $cartDetails = [];
            $total = 0;

            foreach ($cart as $item) {
                $itemDetails = $this->getCartItemDetails($item);
                $cartDetails[] = $itemDetails;
                $total += $itemDetails['total_amount'];
            }

            return response()->json([
                'success' => true,
                'cart_items' => $cartDetails,
                'total_amount' => $total,
                'currency' => session('currency', 'LKR'),
                'item_count' => count($cartDetails)
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving cart', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving cart contents'
            ], 500);
        }
    }

    /**
     * Update cart item
     */
    public function updateCartItem(Request $request, string $itemId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'quantity' => 'required|integer|min:1|max:10',
                'selected_addons' => 'nullable|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $updated = $this->updateCartItemData($itemId, $request->all());

            if (!$updated) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart item not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Cart updated successfully',
                'cart_total' => $this->getCartTotal()
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating cart item', [
                'item_id' => $itemId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error updating cart item'
            ], 500);
        }
    }

    /**
     * Remove item from cart
     */
    public function removeFromCart(string $itemId)
    {
        try {
            $cart = session()->get('booking_cart', []);

            if (isset($cart[$itemId])) {
                unset($cart[$itemId]);
                session()->put('booking_cart', $cart);

                return response()->json([
                    'success' => true,
                    'message' => 'Item removed from cart',
                    'cart_total' => $this->getCartTotal()
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Item not found in cart'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error removing cart item', [
                'item_id' => $itemId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error removing item from cart'
            ], 500);
        }
    }

    /**
     * Get available addons for a vehicle group
     */
    public function getAvailableAddons(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'vehicle_group_id' => 'required|string|exists:vehicle_groups,id',
                'service_type' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // Use BookingFlowService to get available addons
            $addons = $this->bookingFlowService->getAvailableAddons($request->all());

            return response()->json([
                'success' => true,
                'addons' => $addons['data']
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting available addons', [
                'vehicle_group_id' => $request->vehicle_group_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving available addons'
            ], 500);
        }
    }

    // ========================
    // HELPER METHODS
    // ========================

    /**
     * Get or create session ID for tracking
     */
    private function getOrCreateSessionId(): string
    {
        $sessionId = session()->get('booking_session_id');
        if (!$sessionId) {
            $sessionId = Str::uuid()->toString();
            session()->put('booking_session_id', $sessionId);
        }
        return $sessionId;
    }

    /**
     * Get public-friendly price breakdown
     */
    private function getPublicPriceBreakdown($pricing): array
    {
        $basePricing = $pricing['base_pricing'] ?? [];
        $summary = $pricing['summary'] ?? [];

        return [
            'base_fare' => [
                'amount' => $basePricing['base_amount'] ?? 0,
                'description' => 'Base transportation cost'
            ],
            'distance_charges' => [
                'amount' => $basePricing['distance_charges'] ?? 0,
                'description' => 'Distance-based charges'
            ],
            'time_charges' => [
                'amount' => $basePricing['time_charges'] ?? 0,
                'description' => 'Time-based charges'
            ],
            'addon_charges' => [
                'amount' => $summary['addons_total'] ?? 0,
                'description' => 'Additional services'
            ],
            'taxes' => [
                'amount' => $basePricing['tax_amount'] ?? 0,
                'description' => 'Taxes and fees'
            ],
            'total' => [
                'amount' => $summary['total'] ?? 0,
                'description' => 'Total amount'
            ]
        ];
    }

    /**
     * Get available payment options
     */
    private function getAvailablePaymentOptions($pricing): array
    {
        $totalAmount = $pricing['summary']['total'] ?? 0;

        return [
            'cash' => [
                'available' => true,
                'description' => 'Pay cash to driver'
            ],
            'card' => [
                'available' => true,
                'description' => 'Credit/Debit card',
                'processing_fee' => $totalAmount * 0.03 // 3% processing fee
            ],
            'wallet' => [
                'available' => true,
                'description' => 'Digital wallet payment'
            ],
            'installments' => [
                'available' => $totalAmount > 1000,
                'description' => 'Pay in installments',
                'min_amount' => 1000
            ]
        ];
    }

    /**
     * Get popular destinations
     */
    private function getPopularDestinations(): array
    {
        return Cache::remember('popular_destinations', 3600, function () {
            // TODO: Query from database based on booking history
            return [
                ['name' => 'Airport Terminal 1', 'bookings' => 1250],
                ['name' => 'City Center Mall', 'bookings' => 980],
                ['name' => 'Business District', 'bookings' => 750],
                ['name' => 'Train Station', 'bookings' => 650],
                ['name' => 'University Campus', 'bookings' => 500]
            ];
        });
    }


    /**
     * Get active promotional offers
     */
    private function getActivePromotionalOffers($search): array
    {
        // TODO: Query active promotions from database
        // Accept both BookingSearch model and stdClass/array for session-based searches
        return [
            [
                'title' => 'First Time User Discount',
                'description' => '15% off your first booking',
                'discount_percentage' => 15,
                'code' => 'WELCOME15',
                'valid_until' => '2025-12-31'
            ]
        ];
    }

    /**
     * Add vehicle to cart with enhanced data
     */
    private function addVehicleToCart(BookingSearch $search, array $data): array
    {
        $cart = session()->get('booking_cart', []);
        $itemId = Str::uuid()->toString();

        $cartItem = [
            'id' => $itemId,
            'search_id' => $search->id,
            'vehicle_group_id' => $data['vehicle_group_id'],
            'quantity' => $data['quantity'] ?? 1,
            'selected_addons' => $data['selected_addons'] ?? [],
            'special_requirements' => $data['special_requirements'] ?? '',
            'added_at' => now(),
            'pricing_snapshot' => $this->getCartItemPricing($search, $data)
        ];

        $cart[$itemId] = $cartItem;
        session()->put('booking_cart', $cart);

        return $cartItem;
    }

    /**
     * Get cart item pricing
     */
    private function getCartItemPricing(BookingSearch $search, array $data): array
    {
        // Calculate pricing for this specific cart item
        $params = [
            'vehicle_group_id' => $data['vehicle_group_id'],
            'service_type' => $search->service_type,
            'from_date' => $search->from_date?->format('Y-m-d'),
            'to_date' => $search->to_date?->format('Y-m-d'),
            'quantity' => $data['quantity'] ?? 1,
            'selected_addons' => $data['selected_addons'] ?? []
        ];

        $pricing = $this->bookingFlowService->calculatePricing($params);
        return $pricing['data'] ?? [];
    }

    /**
     * Get cart item details
     */
    private function getCartItemDetails(array $item): array
    {
        $vehicleGroup = VehicleGroup::find($item['vehicle_group_id']);

        return [
            'id' => $item['id'],
            'vehicle_group' => $vehicleGroup ? $vehicleGroup->toArray() : null,
            'quantity' => $item['quantity'],
            'selected_addons' => $item['selected_addons'],
            'special_requirements' => $item['special_requirements'],
            'pricing' => $item['pricing_snapshot'],
            'total_amount' => ($item['pricing_snapshot']['total_amount'] ?? 0) * $item['quantity'],
            'added_at' => $item['added_at']
        ];
    }

    /**
     * Update cart item data
     */
    private function updateCartItemData(string $itemId, array $data): bool
    {
        $cart = session()->get('booking_cart', []);

        if (!isset($cart[$itemId])) {
            return false;
        }

        $cart[$itemId]['quantity'] = $data['quantity'];
        $cart[$itemId]['selected_addons'] = $data['selected_addons'] ?? [];
        $cart[$itemId]['updated_at'] = now();

        // Recalculate pricing using current search params
        $searchParams = session()->get('current_search_params');
        if ($searchParams) {
            $cart[$itemId]['pricing_snapshot'] = $this->getCartItemPricing($searchParams, $cart[$itemId]);
        }

        session()->put('booking_cart', $cart);
        return true;
    }

    /**
     * Get total cart value
     */
    private function getCartTotal(): float
    {
        $cart = session()->get('booking_cart', []);
        $total = 0;

        foreach ($cart as $item) {
            $total += ($item['pricing_snapshot']['total_amount'] ?? 0) * $item['quantity'];
        }

        return $total;
    }

    /**
     * Check if it's peak time
     */
    private function isPeakTime(?string $date, ?string $time): bool
    {
        // Handle null values with defaults
        $date = $date ?? now()->format('Y-m-d');
        $time = $time ?? '09:00';

        try {
            $dateTime = Carbon::parse($date . ' ' . $time);
            $hour = $dateTime->hour;
            $dayOfWeek = $dateTime->dayOfWeek;

            // Weekend or rush hours (7-9 AM, 5-7 PM on weekdays)
            return $dayOfWeek >= 5 || ($dayOfWeek < 5 && (($hour >= 7 && $hour <= 9) || ($hour >= 17 && $hour <= 19)));
        } catch (\Exception $e) {
            // Return false if date parsing fails
            return false;
        }
    }

    /**
     * Get advance booking discount percentage
     */
    private function getAdvanceBookingDiscount(?string $date): float
    {
        // Handle null date with default (today)
        $date = $date ?? now()->format('Y-m-d');

        try {
            $daysAhead = Carbon::parse($date)->diffInDays(now());

            if ($daysAhead >= 30)
                return 0.15; // 15% for 30+ days
            if ($daysAhead >= 14)
                return 0.10; // 10% for 14+ days
            if ($daysAhead >= 7)
                return 0.05;  // 5% for 7+ days

            return 0;
        } catch (\Exception $e) {
            // Return 0 discount if date parsing fails
            return 0;
        }
    }

    /**
     * Get availability status
     */
    private function getAvailabilityStatus($group): string
    {
        $available = $group['available_vehicles'] ?? 0;
        $total = $group['total_vehicles'] ?? 1;
        $percentage = $available / $total;

        if ($percentage >= 0.7)
            return 'excellent';
        if ($percentage >= 0.4)
            return 'good';
        if ($percentage >= 0.2)
            return 'limited';

        return 'low';
    }

    /**
     * Check if a string is a valid UUID
     */
    private function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    /**
     * Get service features based on service type code
     */
    private function getServiceFeatures(string $code): array
    {
        $features = [
            'airport_transfers' => [
                'Free Cancellations',
                'Meet & Greet included',
                'No Hidden Charges',
            ],
            'point_to_point' => [
                'Point-to-Point Transfers',
                'Reliable Service',
                'Professional Drivers',
                'Real-time GPS Tracking',
                'Competitive Pricing'
            ],
            'ride_now' => [
                'Free Cancellations',
                'No Hidden Charges',
            ],
            'day_rental' => [
                'No Hidden Charges',
                'Free Cancellations',
                'Flexible Drop-off',
                'Insurance Included'
            ],
            'wedding_hire' => [
                'Special Occasion Service',
                'Professional Drivers',
                'Decorated Vehicles',
                'Flexible Timing',
                'Premium Service'
            ],
            'corporate' => [
                'Corporate Accounts',
                'Contract Pricing',
                'Reliable Service',
                'Professional Drivers',
                'Expense Tracking'
            ]
        ];

        return $features[$code] ?? ['Professional Service', 'Reliable Transport', 'Competitive Pricing'];
    }

    /**
     * Get dynamic service configuration for frontend
     */
    public function getServiceConfiguration()
    {
        try {
            // Get all active service types from database
            $serviceTypes = ServiceType::where('is_active', true)
                ->get(['id', 'name', 'slug', 'description', 'code'])
                ->groupBy('code')
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => [
                    'service_types' => $serviceTypes,
                    'categories' => array_keys($serviceTypes)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching service configuration', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load service configuration'
            ], 500);
        }
    }

    /**
     * Get form configuration for a specific service type
     */
    public function getServiceFormConfig(Request $request, string $serviceCode)
    {
        try {
            $serviceType = ServiceType::where('code', $serviceCode)
                ->orWhere('code', $serviceCode)
                ->first();

            if (!$serviceType) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service type not found'
                ], 404);
            }

            // Return service type configuration for form building
            $config = [
                'service_id' => $serviceType->id,
                'service_name' => $serviceType->name,
                'service_slug' => $serviceType->slug,
                'service_code' => $serviceType->code,
                'description' => $serviceType->description,
                'form_type' => $this->getFormType($serviceType->code),
                'fields' => $this->getFormFields($serviceType->code)
            ];

            return response()->json([
                'success' => true,
                'data' => $config
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching service form configuration', [
                'service_code' => $serviceCode,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load form configuration'
            ], 500);
        }
    }

    /**
     * Get validation rules for a specific service type
     */
    public function getServiceValidationRules(string $serviceCode)
    {
        try {
            $rules = $this->buildValidationRules($serviceCode);

            return response()->json([
                'success' => true,
                'data' => [
                    'rules' => $rules,
                    'service_code' => $serviceCode
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching validation rules', [
                'service_code' => $serviceCode,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load validation rules'
            ], 500);
        }
    }

    /**
     * Get form type for service type
     */
    private function getFormType(string $code): string
    {
        return match ($code) {
            'airport_transfers', 'point_to_point' => 'transfer',
            'ride_now' => 'rental',
            'day_rental' => 'rental',
            'wedding_hire' => 'special_occasion',
            'corporate' => 'inquiry',
            default => 'generic'
        };
    }

    /**
     * Get form fields for service type
     */
    private function getFormFields(string $code): array
    {
        return match ($code) {
            'airport_transfers' => [
                ['name' => 'from', 'label' => 'From Location', 'type' => 'text', 'required' => true],
                ['name' => 'to', 'label' => 'To Location', 'type' => 'text', 'required' => true],
                ['name' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true],
                ['name' => 'time', 'label' => 'Time', 'type' => 'time', 'required' => true],
                // ['name' => 'passengers', 'label' => 'Passengers', 'type' => 'number', 'required' => true],
            ],
            'point_to_point' => [
                ['name' => 'from', 'label' => 'From Location', 'type' => 'text', 'required' => true],
                ['name' => 'to', 'label' => 'To Location', 'type' => 'text', 'required' => true],
                ['name' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true],
                ['name' => 'time', 'label' => 'Time', 'type' => 'time', 'required' => true],
                // ['name' => 'passengers', 'label' => 'Passengers', 'type' => 'number', 'required' => true],
            ],
            'ride_now' => [
                ['name' => 'pickup_date', 'label' => 'Pickup Date', 'type' => 'date', 'required' => true],
                ['name' => 'dropoff_date', 'label' => 'Dropoff Date', 'type' => 'date', 'required' => true],
                ['name' => 'pickup_time', 'label' => 'Pickup Time', 'type' => 'time', 'required' => true],
                ['name' => 'dropoff_time', 'label' => 'Dropoff Time', 'type' => 'time', 'required' => true],
                // ['name' => 'passengers', 'label' => 'Passengers', 'type' => 'number', 'required' => true],
                ['name' => 'package_type', 'label' => 'Package Type', 'type' => 'select', 'required' => true],
            ],
            'wedding_hire' => [
                ['name' => 'date', 'label' => 'Event Date', 'type' => 'date', 'required' => true],
                ['name' => 'time', 'label' => 'Start Time', 'type' => 'time', 'required' => true],
                ['name' => 'package_hours', 'label' => 'Package Hours', 'type' => 'number', 'required' => true],
                // ['name' => 'passengers', 'label' => 'Passengers', 'type' => 'number', 'required' => true],
            ],
            'corporate' => [
                ['name' => 'company_name', 'label' => 'Company Name', 'type' => 'text', 'required' => true],
                ['name' => 'contact_person', 'label' => 'Contact Person', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => true],
                ['name' => 'contract_type', 'label' => 'Contract Type', 'type' => 'select', 'required' => true],
            ],
            default => []
        };
    }

    /**
     * Build validation rules for service type
     */
    private function buildValidationRules(string $code): array
    {
        return match ($code) {
            'airport_transfers' => [
                'from' => 'required|string|max:255',
                'to' => 'required|string|max:255',
                'date' => 'required|date_format:d/m/Y|after:today',
                'time' => 'required|date_format:H:i',
                'passengers' => 'nullable|integer|min:1|max:10',
            ],
            'point_to_point' => [
                'from' => 'required|string|max:255',
                'to' => 'required|string|max:255',
                'date' => 'required|date_format:d/m/Y|after:today',
                'time' => 'required|date_format:H:i',
                'passengers' => 'nullable|integer|min:1|max:10',
            ],
            'ride_now' => [
                'pickup_date' => 'required|date_format:d/m/Y|after:today',
                // 'dropoff_date' => 'required|date_format:d/m/Y|after:pickup_date',
                'pickup_time' => 'required|date_format:H:i',
                'dropoff_time' => 'required|date_format:H:i',
                'passengers' => 'nullable|integer|min:1|max:10',
            ],
            'wedding_hire' => [
                'date' => 'required|date_format:d/m/Y|after:today',
                'time' => 'required|date_format:H:i',
                'package_hours' => 'required|integer|in:6,8,12',
                'passengers' => 'nullable|integer|min:1|max:10',
            ],
            'corporate' => [
                'company_name' => 'required|string|max:255',
                'contact_person' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'required|string|max:20',
                'contract_type' => 'required|in:weekly,monthly,quarterly,annual',
            ],
            default => []
        };
    }

    /**
     * Show the Point-to-Point (Drop & Pickup) service page
     */
    public function pointToPoint()
    {
        try {
            return view('point-to-point');
        } catch (\Exception $e) {
            Log::error('Error loading point-to-point page', [
                'error' => $e->getMessage()
            ]);
            return redirect()->route('home')->with('error', 'Unable to load the Point-to-Point service page.');
        }
    }

    /**
     * Create an inquiry for Request Quotation
     */
    public function requestQuotation(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'vehicle_group_id' => 'required|string|exists:vehicle_groups,id',
                'customer_name' => 'required|string|max:255',
                'customer_email' => 'required|email|max:255',
                'customer_phone' => 'required|string|max:20',
                'service_type' => 'required|string',
                'pickup_location' => 'nullable|string|max:500',
                'dropoff_location' => 'nullable|string|max:500',
                'travel_date' => 'nullable|date',
                'travel_time' => 'nullable|string',
                'passengers' => 'nullable|integer|min:1|max:50',
                'special_requirements' => 'nullable|string|max:1000',
                'company_name' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return back()->withErrors($validator)->withInput()
                    ->with('error', 'Please check your information and try again.');
            }

            // Get search parameters from session for context
            $searchParams = session()->get('current_search_params', []);
            $vehicleGroup = VehicleGroup::findOrFail($request->vehicle_group_id);

            // Create inquiry with enhanced context
            $inquiryData = [
                'subject' => "Request Quotation - {$vehicleGroup->name}",
                'message' => $this->buildQuotationMessage($request->all(), $searchParams, $vehicleGroup),
                'status' => 'pending',
                'priority' => 'high',
                'customer_id' => null, // Will be created if needed
                'inquiry_type' => 'quotation_request',
                'vehicle_group_id' => $request->vehicle_group_id,
                'service_type' => $request->service_type,
                'contact_name' => $request->customer_name,
                'contact_email' => $request->customer_email,
                'contact_phone' => $request->customer_phone,
                'company_name' => $request->company_name,
                'search_context' => json_encode($searchParams),
                'form_data' => json_encode($request->except(['_token'])),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ];

            // Create the inquiry
            $inquiry = \App\Models\Inquiry::create($inquiryData);

            // Trigger email notifications
            $this->sendQuotationRequestEmails($inquiry, $request->all(), $vehicleGroup);

            return redirect()->back()
                ->with('success', 'Your quotation request has been submitted successfully! Our team will contact you within 2 business hours with a detailed quote.');

        } catch (\Exception $e) {
            Log::error('Error processing quotation request', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return back()->withInput()
                ->with('error', 'An error occurred while submitting your request. Please try again or contact us directly.');
        }
    }

    /**
     * Build the inquiry message with all relevant details
     */
    private function buildQuotationMessage(array $requestData, array $searchParams, VehicleGroup $vehicleGroup): string
    {
        $message = "QUOTATION REQUEST\n\n";
        $message .= "Vehicle Group: {$vehicleGroup->name}\n";
        $message .= "Customer: {$requestData['customer_name']}\n";
        $message .= "Email: {$requestData['customer_email']}\n";
        $message .= "Phone: {$requestData['customer_phone']}\n";

        if (!empty($requestData['company_name'])) {
            $message .= "Company: {$requestData['company_name']}\n";
        }

        $message .= "\nSERVICE DETAILS:\n";
        $message .= "Service Type: {$requestData['service_type']}\n";

        if (!empty($requestData['pickup_location'])) {
            $message .= "Pickup: {$requestData['pickup_location']}\n";
        }
        if (!empty($requestData['dropoff_location'])) {
            $message .= "Dropoff: {$requestData['dropoff_location']}\n";
        }
        if (!empty($requestData['travel_date'])) {
            $message .= "Date: {$requestData['travel_date']}\n";
        }
        if (!empty($requestData['travel_time'])) {
            $message .= "Time: {$requestData['travel_time']}\n";
        }
        if (!empty($requestData['passengers'])) {
            $message .= "Passengers: {$requestData['passengers']}\n";
        }

        if (!empty($searchParams)) {
            $message .= "\nORIGINAL SEARCH CONTEXT:\n";
            if (isset($searchParams['pickup_location']['address'])) {
                $message .= "From: {$searchParams['pickup_location']['address']}\n";
            }
            if (isset($searchParams['dropoff_location']['address'])) {
                $message .= "To: {$searchParams['dropoff_location']['address']}\n";
            }
            if (isset($searchParams['from_date'])) {
                $message .= "Travel Date: {$searchParams['from_date']}\n";
            }
            if (isset($searchParams['from_time'])) {
                $message .= "Travel Time: {$searchParams['from_time']}\n";
            }
        }

        if (!empty($requestData['special_requirements'])) {
            $message .= "\nSPECIAL REQUIREMENTS:\n";
            $message .= $requestData['special_requirements'] . "\n";
        }

        $message .= "\nREASON FOR QUOTATION REQUEST:\n";
        $message .= "- Coordinates missing or pricing not available in automated system\n";
        $message .= "- Requires manual calculation and custom pricing\n";

        $message .= "\nSubmitted: " . now()->format('Y-m-d H:i:s') . "\n";

        return $message;
    }

    /**
     * Send email notifications for quotation requests
     */
    private function sendQuotationRequestEmails(\App\Models\Inquiry $inquiry, array $requestData, VehicleGroup $vehicleGroup): void
    {
        try {
            // Send notification to corporate transport admin
            $adminEmail = config('mail.corporate_transport_admin', 'admin@thetaxi.lk');
            $this->mailDispatchService->sendToInternal(
                $adminEmail,
                new \App\Mail\QuotationRequestNotification($inquiry, $requestData, $vehicleGroup)
            );

            // Send confirmation to customer
            $this->mailDispatchService->sendToCustomer(
                $requestData['customer_email'],
                new \App\Mail\QuotationRequestConfirmation($inquiry, $requestData, $vehicleGroup)
            );

            Log::info('Quotation request emails sent successfully', [
                'inquiry_id' => $inquiry->id,
                'inquiry_number' => $inquiry->inquiry_number ?? null,
                'customer_email' => $requestData['customer_email'],
                'admin_email' => $adminEmail,
                'vehicle_group' => $vehicleGroup->name,
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending quotation request emails', [
                'inquiry_id' => $inquiry->id,
                'inquiry_number' => $inquiry->inquiry_number ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Don't throw exception - inquiry was still created successfully
        }
    }

    /**
     * Show the Corporate Transfers service page
     */
    public function corporateTransfers()
    {
        try {
            return view('corporate-transfers');
        } catch (\Exception $e) {
            Log::error('Error loading corporate-transfers page', [
                'error' => $e->getMessage()
            ]);
            return redirect()->route('home')->with('error', 'Unable to load the Corporate Transfers service page.');
        }
    }
}
