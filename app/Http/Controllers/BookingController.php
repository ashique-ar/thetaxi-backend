<?php

namespace App\Http\Controllers;

use App\Services\BookingFlowService;
use App\Services\CurrencyService;
use App\Services\DiscountService;
use App\Models\BookingSearch;
use App\Models\Vehicle\VehicleGroup;
use App\Models\ServiceType;
use App\Http\Requests\BookingSearchRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\Carbon;

class BookingController extends Controller
{
    protected BookingFlowService $bookingFlowService;
    protected CurrencyService $currencyService;
    protected DiscountService $discountService;
    
    public function __construct(
        BookingFlowService $bookingFlowService,
        CurrencyService $currencyService,
        DiscountService $discountService
    ) {
        $this->bookingFlowService = $bookingFlowService;
        $this->currencyService = $currencyService;
        $this->discountService = $discountService;
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
            
            // Store search params and context in session for results page
            session()->put('current_search_params', $searchParams);
            session()->put('search_timestamp', now());
            session()->put('session_id', $sessionId);
            session()->put('backend_service_type_id', $serviceType->id);
            session()->put('frontend_service', $frontendService);

            // Log search activity for analytics
            Log::info('Public booking search initiated', [
                'frontend_service' => $frontendService,
                'service_type_id' => $serviceType->id,
                'session_id' => $sessionId,
                'search_params' => $searchParams,
                'user_ip' => $request->ip()
            ]);

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
                $params['pickup_location'] = $this->formatLocation($requestData, 'from');
                $params['dropoff_location'] = $this->formatLocation($requestData, 'to');
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
                $params['pickup_location'] = $this->formatLocation($requestData, 'from');
                $params['dropoff_location'] = $this->formatLocation($requestData, 'to');
                break;
                
            case 'ride_now':
                $pickupDate = Carbon::parse($requestData['pickup_date'] ?? $requestData['date']);
                // Default to same date if no dropoff date provided (1 day booking)
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
                $params['pickup_location'] = $this->formatLocation($requestData, 'from');
                $params['dropoff_location'] = $this->formatLocation($requestData, 'to');
                $params['package_hours'] = $requestData['package_hours'] ?? 6;
                break;

            case 'corporate':
                $params['from_date'] = Carbon::parse($requestData['date'])->format('Y-m-d');
                $params['to_date'] = Carbon::parse($requestData['date'])->format('Y-m-d');
                $params['from_time'] = $requestData['time'] ?? '00:00';
                $params['to_time'] = $requestData['time'] ?? '23:59';
                $params['pickup_location'] = $this->formatLocation($requestData, 'from');
                $params['contract_type'] = $requestData['contract_type'] ?? 'weekly';
                break;
        }
        
        // Add common parameters
        $params['passengers'] = (int)($requestData['passengers'] ?? 1);
        
        return $params;
    }
    
    /**
     * Format location data for BookingFlowService
     */
    protected function formatLocation(array $data, string $prefix): array
    {
        $location = [
            'address' => $data[$prefix] ?? '',
            'latitude' => $data["{$prefix}_lat"] ?? null,
            'longitude' => $data["{$prefix}_lng"] ?? null,
        ];
        
        // Ensure numeric values
        if ($location['latitude']) {
            $location['latitude'] = (float) $location['latitude'];
        }
        if ($location['longitude']) {
            $location['longitude'] = (float) $location['longitude'];
        }
        
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
            
            // Check if search is expired (older than 2 hours)
            if ($searchTimestamp && Carbon::parse($searchTimestamp)->diffInHours(now()) > 2) {
                return redirect()->route('home')
                    ->with('warning', 'Your search has expired. Please start a new search for updated prices.');
            }
            
            // Call BookingFlowService to get available vehicle groups (same as API)
            Log::info('Calling BookingFlowService with params', ['params' => $searchParams]);
            $availabilityData = $this->bookingFlowService->getAvailableVehicleGroups($searchParams);
            Log::info('BookingFlowService returned', [
                'data_count' => isset($availabilityData['data']) ? count($availabilityData['data']) : count($availabilityData),
                'has_pagination' => isset($availabilityData['pagination'])
            ]);
            
            // Extract data and pagination
            $vehicleGroups = $availabilityData['data'] ?? $availabilityData;
            $pagination = $availabilityData['pagination'] ?? null;
            $totalJourneyDistance = $availabilityData['total_journey_distance_km'] ?? null;
            
            Log::info('Vehicle groups extracted', ['count' => count($vehicleGroups)]);
            
            // Transform results for view (add public-specific enhancements)
            $transformedData = $this->transformResultsForPublicView($vehicleGroups, $searchParams, $pricingContext);
            
            Log::info('Transformed data', ['count' => count($transformedData)]);
            
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
                ]
            );
            
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
        
        foreach ($vehicleGroups as $index => $groupData) {
            // BookingFlowService returns data FLAT, not nested under 'group' key
            // The structure has: id, name, category, pricing_info, available_count, etc. directly
            
            Log::info("Processing vehicle group {$index}", [
                'id' => $groupData['id'] ?? 'no id',
                'name' => $groupData['name'] ?? 'no name',
                'has_pricing' => isset($groupData['pricing_info']),
                'available_count' => $groupData['available_count'] ?? 0
            ]);
            
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
                $serviceTypeModel = \App\Models\ServiceType::find($serviceTypeId);
                $serviceType = $serviceTypeModel ? $serviceTypeModel->code : 'point_to_point';
            }
            
            $formattedPricing = !empty($pricingInfo) ? [
                'base_amount' => $pricingInfo['base_amount'] ?? 0,
                'total_amount' => $pricingInfo['total_amount'] ?? 0,
                'currency' => $pricingInfo['currency'] ?? 'LKR',
                'breakdown' => $pricingInfo['breakdown'] ?? [],
                'duration_info' => array_merge($pricingInfo['duration_info'] ?? [], [
                    'package_hours' => $searchParams['package_hours'] ?? null
                ]),
                'service_type' => $serviceType,
            ] : [];
            
            // Build result using the ACTUAL structure from BookingFlowService
            $results[] = [
                // Direct mapping from BookingFlowService response
                'id' => $groupData['id'],
                'name' => $groupData['name'],
                'description' => $groupData['description'] ?? '',
                'seating_capacity' => $groupData['features']['seating_capacity'] ?? null,
                'luggage_capacity' => $groupData['features']['luggage_capacity'] ?? null,
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
                'service_features' => $this->getServiceFeatures($searchParams['service_type'] ?? 'airport_transfers'),
                'savings_info' => [],
                'payment_options' => $this->getAvailablePaymentOptions($formattedPricing),
            ];
        }
        
        Log::info("Transformation complete", ['result_count' => count($results)]);
        
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

            Log::info('Corporate enquiry submitted', [
                'company' => $request->company_name,
                'contact' => $request->contact_person,
                'email' => $request->email
            ]);

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
     * Track search result view for analytics
     */
    private function trackSearchResultView(BookingSearch $search, Request $request)
    {
        try {
            // Increment view count
            $search->view_count = ($search->view_count ?? 0) + 1;
            $search->save();
            
            // Log for analytics
            Log::info('Search results viewed', [
                'search_id' => $search->id,
                'service_type' => $search->service_type,
                'session_id' => $search->session_id,
                'view_count' => $search->fresh()->view_count ?? 1
            ]);
        } catch (\Exception $e) {
            // If view count tracking fails, log the error but don't break the search
            Log::warning('Failed to track search result view', [
                'search_id' => $search->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get popular destinations
     */
    private function getPopularDestinations(): array
    {
        return Cache::remember('popular_destinations', 3600, function() {
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
     * Get search summary for display
     */
    private function getSearchSummary(BookingSearch $search): array
    {
        Log::info('Generating search summary', ['search_id' => $search->id]);
        Log::info('Generating search summary', ['search' => $search]);
        return [
            'service_type' => ucwords(str_replace('-', ' ', $search->service_type)),
            'route' => $search->pickup_location . ' → ' . $search->dropoff_location,
            'date_time' => $search->from_date?->format('M d, Y') . ' at ' . $search->from_time,
            'duration' => $search->calculateDuration(),
            'passengers' => $search->passengers ?? 1
        ];
    }

    /**
     * Get similar searches for recommendations
     */
    private function getSimilarSearches(BookingSearch $search): array
    {
        return Cache::remember("similar_searches_{$search->id}", 1800, function() use ($search) {
            // TODO: Implement similarity algorithm
            return [];
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
            
            if ($daysAhead >= 30) return 0.15; // 15% for 30+ days
            if ($daysAhead >= 14) return 0.10; // 10% for 14+ days
            if ($daysAhead >= 7) return 0.05;  // 5% for 7+ days
            
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
        
        if ($percentage >= 0.7) return 'excellent';
        if ($percentage >= 0.4) return 'good';
        if ($percentage >= 0.2) return 'limited';
        
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
                'Professional Airport Transfers',
                'Door-to-Door Service',
                'Meet & Greet Available',
                'Real-time Tracking',
                'Affordable Rates'
            ],
            'point_to_point' => [
                'Point-to-Point Transfers',
                'Reliable Service',
                'Professional Drivers',
                'Real-time GPS Tracking',
                'Competitive Pricing'
            ],
            'ride_now' => [
                'Flexible Rental Packages',
                'Self-Drive Options',
                'Long-Term Discounts',
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
        return match($code) {
            'airport_transfers', 'point_to_point' => 'transfer',
            'ride_now' => 'rental',
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
        return match($code) {
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
        return match($code) {
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
                'dropoff_date' => 'required|date_format:d/m/Y|after:pickup_date',
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
