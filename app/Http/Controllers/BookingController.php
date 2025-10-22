<?php

namespace App\Http\Controllers;

use App\Services\BookingSearchService;
use App\Services\BookingFlowService;
use App\Services\CurrencyService;
use App\Services\DiscountService;
use App\Models\BookingSearch;
use App\Models\Vehicle\VehicleGroup;
use App\Models\ServiceType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\Carbon;

class BookingController extends Controller
{
    protected BookingSearchService $searchService;
    protected BookingFlowService $bookingFlowService;
    protected CurrencyService $currencyService;
    protected DiscountService $discountService;
    
    public function __construct(
        BookingSearchService $searchService,
        BookingFlowService $bookingFlowService,
        CurrencyService $currencyService,
        DiscountService $discountService
    ) {
        $this->searchService = $searchService;
        $this->bookingFlowService = $bookingFlowService;
        $this->currencyService = $currencyService;
        $this->discountService = $discountService;
    }
    
    /**
     * Handle enhanced booking search request with advanced pricing
     */
    public function search(Request $request)
    {
        try {
            // Validate based on service type
            $serviceType = $request->input('service_type');
            
            $validator = $this->getValidator($request, $serviceType);
            
            if ($validator->fails()) {
                return redirect()->back()
                    ->withErrors($validator)
                    ->withInput()
                    ->with('error', 'Please check your search criteria and try again.');
            }

            // Get or create session ID for this search
            $sessionId = $this->getOrCreateSessionId();

            // Enhanced search data preparation
            $searchData = $this->prepareSearchData($request->all(), $sessionId);
            
            // Store search in database with enhanced tracking
            $bookingSearch = $this->searchService->storeSearch($searchData, $sessionId);
            
            // Store search ID in session for easy access
            session()->put('current_search_id', $bookingSearch->id);
            session()->put('search_timestamp', now());

            // Log search activity for analytics
            Log::info('Public booking search initiated', [
                'search_id' => $bookingSearch->id,
                'service_type' => $serviceType,
                'session_id' => $sessionId,
                'user_ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            // Redirect to enhanced search results page
            return redirect()->route('search.results', ['id' => $bookingSearch->id])
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
     * Show enhanced search results page with comprehensive pricing
     */
    public function showResults(Request $request, ?string $id = null)
    {
        try {
            // Get search from ID or session
            if ($id) {
                $search = $this->searchService->getSearch($id);
            } else {
                $searchId = session()->get('current_search_id');
                $search = $searchId ? $this->searchService->getSearch($searchId) : null;
            }
            
            if (!$search) {
                return redirect()->route('home')
                    ->with('error', 'No search data found. Please start a new search.');
            }
            
            // Check if search is expired (older than 2 hours)
            if ($search->created_at->diffInHours(now()) > 2) {
                return redirect()->route('home')
                    ->with('warning', 'Your search has expired. Please start a new search for updated prices.');
            }
            
            // Get enhanced vehicle groups with comprehensive pricing
            $results = $this->getEnhancedSearchResults($search);
            
            // Get additional data for enhanced UI
            $additionalData = $this->getAdditionalSearchData($search);
            
            // Track search result view
            $this->trackSearchResultView($search, $request);
            
            return view('search-results', array_merge(compact('search', 'results'), $additionalData));

        } catch (\Exception $e) {
            Log::error('Error displaying search results', [
                'search_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->route('home')
                ->with('error', 'An error occurred while loading search results. Please try again.');
        }
    }

    /**
     * Get enhanced search results with comprehensive pricing and availability
     */
    private function getEnhancedSearchResults(BookingSearch $search)
    {
        // Prepare API-style parameters for BookingFlowService
        $params = [
            'service_type' => $search->service_type,
            'from_date' => $search->from_date?->format('Y-m-d'),
            'to_date' => $search->to_date?->format('Y-m-d'),
            'from_time' => $search->from_time,
            'to_time' => $search->to_time,
            'pickup_location' => [
                'latitude' => $search->pickup_lat,
                'longitude' => $search->pickup_lng,
                'address' => $search->pickup_location
            ],
            'dropoff_location' => [
                'latitude' => $search->dropoff_lat,
                'longitude' => $search->dropoff_lng,
                'address' => $search->dropoff_location
            ],
            'passengers' => $search->passengers ?? 1,
            'currency' => session('currency', 'USD'),
            'force_refresh' => false
        ];

        // Use BookingFlowService for comprehensive availability checking
        $availability = $this->bookingFlowService->getAvailableVehicleGroups($params);
        
        // Check if we have valid data - BookingFlowService returns array directly
        if (!$availability || !is_array($availability) || empty($availability)) {
            Log::info('BookingFlowService returned no vehicle data', [
                'params' => $params,
                'response_type' => gettype($availability),
                'response_count' => is_array($availability) ? count($availability) : 0
            ]);
            
            return [
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'message' => 'No vehicles available for your search criteria'
                ]
            ];
        }
        
        // Wrap the response in expected format if it's a direct array
        if (is_array($availability) && !isset($availability['data'])) {
            $availability = [
                'data' => $availability,
                'meta' => [
                    'total' => count($availability),
                    'message' => 'Vehicles found successfully'
                ]
            ];
        }
        
        // Enhanced pricing calculation for each vehicle group
        foreach ($availability['data'] as &$group) {
            try {
                $group['enhanced_pricing'] = $this->calculateEnhancedPricing($group, $search);
                $group['availability_details'] = $this->getAvailabilityDetails($group, $params);
                $group['recommended'] = $this->isRecommendedGroup($group, $search);
            } catch (\Exception $e) {
                Log::warning('Pricing calculation failed for vehicle group ' . ($group['id'] ?? 'unknown'), [
                    'error' => $e->getMessage(),
                    'group' => $group
                ]);
                
                // Set default values for failed calculations
                $group['enhanced_pricing'] = [
                    'total_amount' => 0,
                    'currency' => session('currency', 'USD'),
                    'error' => 'Pricing calculation failed'
                ];
                $group['availability_details'] = [];
                $group['recommended'] = false;
            }
        }
        
        // Sort by recommendation and price
        usort($availability['data'], function($a, $b) {
            if ($a['recommended'] !== $b['recommended']) {
                return $b['recommended'] - $a['recommended'];
            }
            return $a['enhanced_pricing']['total_amount'] <=> $b['enhanced_pricing']['total_amount'];
        });

        return $availability;
    }

    /**
     * Calculate enhanced pricing with discounts and dynamic adjustments
     */
    private function calculateEnhancedPricing($group, BookingSearch $search)
    {
        // Ensure service_type is UUID, not string code
        $serviceTypeId = $search->service_type;
        if (!$this->isValidUuid($serviceTypeId)) {
            // Convert kebab-case to UPPER_CASE format for database lookup
            $codeToSearch = strtoupper(str_replace('-', '_', $serviceTypeId));
            
            $serviceType = ServiceType::where('code', $codeToSearch)
                ->orWhere('name', $serviceTypeId)
                ->orWhere('code', $serviceTypeId)
                ->first();
            $serviceTypeId = $serviceType?->id ?? $serviceTypeId;
        }
        
        $pricingParams = [
            'vehicle_group_id' => $group['id'],
            'service_type' => $serviceTypeId,
            'from_date' => $search->from_date?->format('Y-m-d'),
            'to_date' => $search->to_date?->format('Y-m-d'),
            'from_time' => $search->from_time,
            'to_time' => $search->to_time,
            'pickup_location' => [
                'latitude' => $search->pickup_lat,
                'longitude' => $search->pickup_lng
            ],
            'dropoff_location' => [
                'latitude' => $search->dropoff_lat,
                'longitude' => $search->dropoff_lng
            ],
            'distance_km' => $search->distance_km ?? 0,
            'duration_hours' => $search->duration_hours ?? 1,
            'currency' => session('currency', 'USD')
        ];

        try {
            // Use BookingFlowService for comprehensive pricing
            $pricing = $this->bookingFlowService->calculatePricing($pricingParams);
            
            // Check if pricing calculation was successful
            if (!$pricing || !isset($pricing['data'])) {
                throw new \Exception('Invalid pricing response from BookingFlowService');
            }
            
            // Add public-specific enhancements
            $pricing['data']['savings'] = $this->calculatePotentialSavings($group, $pricing['data']);
            $pricing['data']['price_breakdown_public'] = $this->getPublicPriceBreakdown($pricing['data']);
            $pricing['data']['payment_options'] = $this->getAvailablePaymentOptions($pricing['data']);
            
            return $pricing['data'];
            
        } catch (\Exception $e) {
            Log::warning('Enhanced pricing calculation failed', [
                'vehicle_group_id' => $group['id'] ?? 'unknown',
                'service_type' => $serviceTypeId,
                'error' => $e->getMessage(),
                'params' => $pricingParams
            ]);
            
            // Return fallback pricing
            return [
                'total_amount' => 0,
                'currency' => session('currency', 'USD'),
                'base_price' => 0,
                'taxes' => 0,
                'fees' => 0,
                'error' => 'Pricing calculation failed: ' . $e->getMessage(),
                'savings' => [],
                'price_breakdown_public' => [],
                'payment_options' => []
            ];
        }
    }

    /**
     * Get additional data for enhanced search results UI
     */
    private function getAdditionalSearchData(BookingSearch $search)
    {
        return [
            'service_types' => ServiceType::active()->get(),
            'popular_destinations' => $this->getPopularDestinations(),
            'current_currency' => session('currency', 'USD'),
            'available_currencies' => $this->currencyService->getAvailableCurrencies(),
            'search_summary' => $this->getSearchSummary($search),
            'similar_searches' => $this->getSimilarSearches($search),
            'promotional_offers' => $this->getActivePromotionalOffers($search)
        ];
    }

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

            $search = $this->searchService->getSearch($request->search_id);
            if (!$search) {
                return response()->json([
                    'success' => false,
                    'message' => 'Search not found'
                ], 404);
            }

            // Add to cart with pricing calculation
            $cartItem = $this->addVehicleToCart($search, $request->all());

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
                'currency' => session('currency', 'USD'),
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
     * Prepare enhanced search data
     */
    private function prepareSearchData(array $requestData, string $sessionId): array
    {
        $searchData = $requestData;
        $searchData['session_id'] = $sessionId;
        $searchData['ip_address'] = request()->ip();
        $searchData['user_agent'] = request()->userAgent();
        
        // Convert service type code to UUID if it's a string
        if (!empty($searchData['service_type']) && !$this->isValidUuid($searchData['service_type'])) {
            // Convert kebab-case to UPPER_CASE format for database lookup
            $codeToSearch = strtoupper(str_replace('-', '_', $searchData['service_type']));
            
            $serviceType = ServiceType::where('code', $codeToSearch)
                ->orWhere('name', $searchData['service_type'])
                ->orWhere('code', $searchData['service_type'])
                ->first();
            
            if ($serviceType) {
                $searchData['service_type'] = $serviceType->id;
            } else {
                Log::warning('Service type not found in database', [
                    'requested_type' => $searchData['service_type'],
                    'converted_code' => $codeToSearch,
                    'available_types' => ServiceType::pluck('code', 'id')->toArray()
                ]);
            }
        }
        
        // Add coordinate resolution if needed
        if (empty($searchData['pickup_lat']) && !empty($searchData['pickup'])) {
            // TODO: Implement geocoding service
            // $coordinates = $this->geocodingService->getCoordinates($searchData['pickup']);
            // $searchData['pickup_lat'] = $coordinates['lat'];
            // $searchData['pickup_lng'] = $coordinates['lng'];
        }

        return $searchData;
    }

    /**
     * Get availability details for a vehicle group
     */
    private function getAvailabilityDetails($group, $params): array
    {
        // Handle null date/time values gracefully
        $fromDate = $params['from_date'] ?? now()->format('Y-m-d');
        $fromTime = $params['from_time'] ?? '09:00';
        
        return [
            'total_vehicles' => $group['total_vehicles'] ?? 0,
            'available_vehicles' => $group['available_vehicles'] ?? 0,
            'utilization_rate' => $group['utilization_rate'] ?? 0,
            'peak_time' => $this->isPeakTime($fromDate, $fromTime),
            'advance_booking_discount' => $this->getAdvanceBookingDiscount($fromDate),
            'availability_status' => $this->getAvailabilityStatus($group)
        ];
    }

    /**
     * Check if this is a recommended vehicle group
     */
    private function isRecommendedGroup($group, BookingSearch $search): bool
    {
        // Algorithm to determine recommendation based on:
        // - Price competitiveness
        // - Availability
        // - Customer ratings
        // - Historical booking patterns
        
        $score = 0;
        
        // Price factor (lower price = higher score)
        if (isset($group['enhanced_pricing']['total_amount'])) {
            $score += 30; // Base score for having pricing
        }
        
        // Availability factor
        if (($group['available_vehicles'] ?? 0) >= ($search->passengers ?? 1)) {
            $score += 25;
        }
        
        // Rating factor
        if (($group['average_rating'] ?? 0) >= 4.0) {
            $score += 25;
        }
        
        // Popular vehicle type
        if (in_array($group['category'] ?? '', ['economy', 'standard'])) {
            $score += 20;
        }
        
        return $score >= 70; // 70+ score means recommended
    }

    /**
     * Calculate potential savings
     */
    private function calculatePotentialSavings($group, $pricing): array
    {
        $savings = [
            'advance_booking' => 0,
            'loyalty_discount' => 0,
            'promotional_offer' => 0,
            'bulk_booking' => 0,
            'total_savings' => 0
        ];

        // Calculate advance booking savings
        $bookingDaysAhead = Carbon::parse($pricing['from_date'] ?? now())->diffInDays(now());
        if ($bookingDaysAhead >= 7) {
            $savings['advance_booking'] = $pricing['base_amount'] * 0.1; // 10% for 7+ days
        } elseif ($bookingDaysAhead >= 3) {
            $savings['advance_booking'] = $pricing['base_amount'] * 0.05; // 5% for 3+ days
        }

        // TODO: Add other savings calculations
        
        $savings['total_savings'] = array_sum([
            $savings['advance_booking'],
            $savings['loyalty_discount'],
            $savings['promotional_offer'],
            $savings['bulk_booking']
        ]);

        return $savings;
    }

    /**
     * Get public-friendly price breakdown
     */
    private function getPublicPriceBreakdown($pricing): array
    {
        return [
            'base_fare' => [
                'amount' => $pricing['base_amount'] ?? 0,
                'description' => 'Base transportation cost'
            ],
            'distance_charges' => [
                'amount' => $pricing['distance_charges'] ?? 0,
                'description' => 'Distance-based charges'
            ],
            'time_charges' => [
                'amount' => $pricing['time_charges'] ?? 0,
                'description' => 'Time-based charges'
            ],
            'addon_charges' => [
                'amount' => $pricing['addon_total'] ?? 0,
                'description' => 'Additional services'
            ],
            'taxes' => [
                'amount' => $pricing['tax_amount'] ?? 0,
                'description' => 'Taxes and fees'
            ],
            'total' => [
                'amount' => $pricing['total_amount'] ?? 0,
                'description' => 'Total amount'
            ]
        ];
    }

    /**
     * Get available payment options
     */
    private function getAvailablePaymentOptions($pricing): array
    {
        return [
            'cash' => [
                'available' => true,
                'description' => 'Pay cash to driver'
            ],
            'card' => [
                'available' => true,
                'description' => 'Credit/Debit card',
                'processing_fee' => $pricing['total_amount'] * 0.03 // 3% processing fee
            ],
            'wallet' => [
                'available' => true,
                'description' => 'Digital wallet payment'
            ],
            'installments' => [
                'available' => ($pricing['total_amount'] ?? 0) > 1000,
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
            $search->increment('view_count');
            
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
    private function getActivePromotionalOffers(BookingSearch $search): array
    {
        // TODO: Query active promotions from database
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
        
        // Recalculate pricing
        $search = $this->searchService->getSearch($cart[$itemId]['search_id']);
        $cart[$itemId]['pricing_snapshot'] = $this->getCartItemPricing($search, $cart[$itemId]);
        
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
     * Get validator based on service type with enhanced validation
     */
    private function getValidator(Request $request, $serviceType)
    {
        switch ($serviceType) {
            case 'airport-transfer':
                return Validator::make($request->all(), [
                    'service_type' => 'required|string',
                    'transfer_type' => 'required|in:from-airport,to-airport',
                    'from' => 'nullable|string|max:255',
                    'to' => 'nullable|string|max:255',
                    'from_lat' => 'nullable|numeric|between:-90,90',
                    'from_lng' => 'nullable|numeric|between:-180,180',
                    'to_lat' => 'nullable|numeric|between:-90,90',
                    'to_lng' => 'nullable|numeric|between:-180,180',
                    'date' => 'required|date|after_or_equal:today',
                    'time' => 'required|date_format:H:i',
                    'passengers' => 'nullable|integer|min:1|max:15'
                ], [
                    'date.after_or_equal' => 'Booking date must be today or in the future.',
                    'time.date_format' => 'Please enter a valid time format.',
                    'passengers.max' => 'Maximum 15 passengers allowed per booking.'
                ]);

            case 'drop-pickup':
                $rules = [
                    'service_type' => 'required|string',
                    'pickup' => 'required|string|max:255',
                    'dropoff' => 'required|string|max:255',
                    'pickup_lat' => 'nullable|numeric|between:-90,90',
                    'pickup_lng' => 'nullable|numeric|between:-180,180',
                    'dropoff_lat' => 'nullable|numeric|between:-90,90',
                    'dropoff_lng' => 'nullable|numeric|between:-180,180',
                    'date' => 'required|date|after_or_equal:today',
                    'time' => 'required|date_format:H:i',
                    'passengers' => 'nullable|integer|min:1|max:15',
                    'need_return' => 'nullable|boolean'
                ];

                // Add return transfer validation if needed
                if ($request->input('need_return') == '1') {
                    $rules['return_pickup'] = 'required|string|max:255';
                    $rules['return_dropoff'] = 'required|string|max:255';
                    $rules['return_date'] = 'required|date|after_or_equal:date';
                    $rules['return_time'] = 'required|date_format:H:i';
                }

                return Validator::make($request->all(), $rules, [
                    'date.after_or_equal' => 'Pickup date must be today or in the future.',
                    'return_date.after_or_equal' => 'Return date must be on or after pickup date.',
                    'pickup.required' => 'Pickup location is required.',
                    'dropoff.required' => 'Drop-off location is required.'
                ]);

            case 'rental-packages':
                return Validator::make($request->all(), [
                    'service_type' => 'required|string',
                    'package_type' => 'required|in:taxi-100km,tour-200km',
                    'pickup' => 'required|string|max:255',
                    'dropoff' => 'required|string|max:255',
                    'pickup_lat' => 'nullable|numeric|between:-90,90',
                    'pickup_lng' => 'nullable|numeric|between:-180,180',
                    'dropoff_lat' => 'nullable|numeric|between:-90,90',
                    'dropoff_lng' => 'nullable|numeric|between:-180,180',
                    'pickup_date' => 'required|date|after_or_equal:today',
                    'pickup_time' => 'required|date_format:H:i',
                    'dropoff_date' => 'required|date|after_or_equal:pickup_date',
                    'dropoff_time' => 'required|date_format:H:i',
                    'passengers' => 'nullable|integer|min:1|max:15'
                ], [
                    'pickup_date.after_or_equal' => 'Pickup date must be today or in the future.',
                    'dropoff_date.after_or_equal' => 'Drop-off date must be on or after pickup date.',
                    'package_type.required' => 'Please select a rental package type.'
                ]);

            case 'custom-tour':
                $rules = [
                    'service_type' => 'required|string',
                    'tour_title' => 'nullable|string|max:255',
                    'starting_location' => 'required|string|max:255',
                    'starting_lat' => 'nullable|numeric|between:-90,90',
                    'starting_lng' => 'nullable|numeric|between:-180,180',
                    'pickup_date' => 'required|date|after_or_equal:today',
                    'passengers' => 'nullable|integer|min:1|max:15',
                    'destinations' => 'nullable|array|min:1|max:10',
                    'destinations.*.location' => 'required_with:destinations|string|max:255',
                    'destinations.*.visit_date' => 'nullable|date',
                    'destinations.*.visit_time' => 'nullable|date_format:H:i',
                    'destinations.*.notes' => 'nullable|string|max:500',
                    'destinations.*.lat' => 'nullable|numeric|between:-90,90',
                    'destinations.*.lng' => 'nullable|numeric|between:-180,180',
                    'tour_duration_days' => 'nullable|integer|min:1|max:30',
                    'budget_range' => 'nullable|string|in:budget,standard,premium,luxury'
                ];
                
                return Validator::make($request->all(), $rules, [
                    'pickup_date.after_or_equal' => 'Tour start date must be today or in the future.',
                    'starting_location.required' => 'Starting location is required.',
                    'destinations.min' => 'At least one destination is required for custom tours.',
                    'destinations.max' => 'Maximum 10 destinations allowed per tour.',
                    'tour_duration_days.max' => 'Maximum tour duration is 30 days.'
                ]);

            case 'corporate-transport':
                return Validator::make($request->all(), [
                    'service_type' => 'required|string',
                    'company_name' => 'required|string|max:255',
                    'contact_person' => 'required|string|max:255',
                    'email' => 'required|email|max:255',
                    'phone' => 'required|string|max:20|regex:/^[\+]?[0-9\s\-\(\)]+$/',
                    'requirements' => 'required|string|min:10|max:1000',
                    'service_frequency' => 'nullable|string|in:one-time,weekly,monthly,ongoing',
                    'estimated_passengers' => 'nullable|integer|min:1|max:50',
                    'preferred_contact_time' => 'nullable|string|in:morning,afternoon,evening,anytime'
                ], [
                    'company_name.required' => 'Company name is required for corporate enquiries.',
                    'email.email' => 'Please provide a valid business email address.',
                    'phone.regex' => 'Please provide a valid phone number.',
                    'requirements.min' => 'Please provide detailed transport requirements (minimum 10 characters).',
                    'requirements.max' => 'Requirements description is too long (maximum 1000 characters).'
                ]);

            default:
                return Validator::make($request->all(), [
                    'service_type' => 'required|string|in:airport-transfer,drop-pickup,rental-packages,custom-tour,corporate-transport',
                ], [
                    'service_type.in' => 'Please select a valid service type.'
                ]);
        }
    }

    /**
     * Check if a string is a valid UUID
     */
    private function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }
}
