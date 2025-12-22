<?php

namespace App\Http\Controllers;

use App\Models\Vehicle\VehicleGroup;
use App\Models\Service\ServiceType;
use App\Services\CartService;
use App\Services\BookingFlowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $totals = $dbCart->totals ?? [];

        $subtotal = $totals['subtotal'] ?? 0;
        $serviceFee = $totals['service_fee'] ?? 0;
        $tax = $totals['tax'] ?? 0;
        $discount = $totals['coupon_discount'] ?? 0;
        $total = $totals['total'] ?? 0;

        return view('cart', compact('cartItems', 'subtotal', 'serviceFee', 'tax', 'discount', 'total', 'dbCart'));
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
            
            $cartArray = cache()->remember($cartCacheKey, 30, function() {
                $dbCart = $this->cartService->getOrCreateCart();
                return $this->cartService->toArray($dbCart);
            });
            
            // Log cart access for monitoring
            Log::info('Cart API accessed', [
                'session_id' => session()->getId(),
                'from_cache' => $fromCache,
                'cart_items_count' => count($cartArray['items'] ?? []),
                'user_agent' => request()->userAgent()
            ]);

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
            // Allow flexible field mapping from frontend
            $validated = $request->validate([
                'vehicle_group_id' => 'sometimes|string',
                'group_id' => 'sometimes|string',
                'name' => 'sometimes|string',
                'group_name' => 'sometimes|string',
                'pickup_date' => 'sometimes|date',
                'from_date' => 'sometimes|date',
                'return_date' => 'sometimes|date',
                'to_date' => 'sometimes|date',
                'from_time' => 'sometimes|string',
                'to_time' => 'sometimes|string',
                'pickup_location' => 'sometimes|string',
                'pickup_lat' => 'sometimes|numeric',
                'pickup_lng' => 'sometimes|numeric',
                'dropoff_location' => 'sometimes|string',
                'dropoff_location' => 'sometimes|string',
                'dropoff_lat' => 'sometimes|numeric',
                'dropoff_lng' => 'sometimes|numeric',
                'search_data' => 'sometimes|array',
                'service_type' => 'sometimes|string'
            ]);

            // Map frontend field names to standard names
            $vehicleId = $validated['vehicle_group_id'] ?? $validated['group_id'] ?? null;
            $name = $validated['name'] ?? $validated['group_name'] ?? 'Vehicle Rental';
            $pickupDate = $validated['pickup_date'] ?? $validated['from_date'] ?? null;
            $returnDate = $validated['return_date'] ?? $validated['to_date'] ?? null;
            $fromTime = $validated['from_time'] ?? ($validated['search_data']['from_time'] ?? '10:00');
            $toTime = $validated['to_time'] ?? ($validated['search_data']['to_time'] ?? '10:00');

            // Extract location data with coordinates
            $pickupLocation = $validated['pickup_location'] ?? ($validated['search_data']['pickup_location'] ?? '');
            $pickupLat = $validated['pickup_lat'] ?? ($validated['search_data']['pickup_lat'] ?? null);
            $pickupLng = $validated['pickup_lng'] ?? ($validated['search_data']['pickup_lng'] ?? null);

            $returnLocation = $validated['dropoff_location'] ?? $validated['dropoff_location'] ?? ($validated['search_data']['dropoff_location'] ?? $pickupLocation);
            $returnLat = $validated['dropoff_lat'] ?? ($validated['search_data']['dropoff_lat'] ?? $pickupLat);
            $returnLng = $validated['dropoff_lng'] ?? ($validated['search_data']['dropoff_lng'] ?? $pickupLng);

            $serviceType = $validated['service_type'] ?? ($validated['search_data']['service_type'] ?? 'airport_transfers');
            $searchData = $validated['search_data'] ?? [];

            // Validate required fields
            if (!$vehicleId || !$pickupDate || !$returnDate) {
                if ($request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Vehicle ID, pickup date, and return date are required'
                    ], 400);
                }
                return redirect()->back()->with('error', 'Missing required booking information.');
            }


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
                    'vehicleGroup' => $vehicleGroup

                ]
            );
            try {
                // Get service type
                $serviceTypeModel = ServiceType::where('code', $serviceType)
                    ->orWhere('name', $serviceType)
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

                    $pricingParams = [
                        'service_type' => $serviceTypeModel->id,
                        'vehicle_groups' => [$vehicleId],
                        'from_date' => $pickupDate,
                        'from_time' => $fromTime,
                        'to_date' => $returnDate,
                        'to_time' => $toTime,
                        'pickup_location' => $pickupLocationArray,
                        'dropoff_location' => $returnLocationArray
                    ];

                    // Get pricing from BookingFlowService
                    $availabilityData = $this->bookingFlowService->getAvailableVehicleGroups($pricingParams);

                    $availabilityData = isset($availabilityData) && isset($availabilityData['data']) ? $availabilityData['data'] : [];
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
                                $totalPrice = (float)$pricingInfo['base_amount']; // This is TOTAL for all days in LKR
                                $perDayPrice = $days > 0 ? $totalPrice / $days : 0; // Calculate per-day in LKR
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
                    'stack_trace'=> $e->getTraceAsString()
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
            
            $cartItem = [
                'vehicle_group_id' => $vehicleId,
                'name' => $vehicleGroup?->name ?? $name,
                'vehicle_type' => $vehicleGroup?->vehicle_type ?? 'Sedan',
                'image' => $vehicleGroup?->thumbnail['path'] ?? null,
                'price' => $isPackageService ? (float)$totalPrice : (float)$perDayPrice, // Use total for packages, per-day for others
                'price_lkr' => $isPackageService ? (float)$totalPrice : (float)$perDayPrice, // Explicitly store LKR price
                'total_price' => (float)$totalPrice, // Store total price in LKR
                'total_price_lkr' => (float)$totalPrice, // Explicitly store LKR total
                'days' => (int)$days,
                'pickup_date' => $pickupDateObj->toDateString(),
                'return_date' => $returnDateObj->toDateString(),
                'from_time' => $fromTime,
                'to_time' => $toTime,
                'pickup_location' => $pickupLocation,
                'pickup_latitude' => $pickupLat,
                'pickup_longitude' => $pickupLng,
                'dropoff_location' => $returnLocation,
                'dropoff_latitude' => $returnLat,
                'return_longitude' => $returnLng,
                'service_type' => $serviceType,
                'service_type_data' => $serviceTypeModel,
                'search_data' => $searchData,
                'base_currency' => 'LKR', // Mark as LKR base pricing
                'is_package' => $isPackageService, // Critical: Mark package services to prevent double multiplication
                'added_at' => now()
            ];

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

        $dbCart = $this->cartService->getOrCreateCart();

        if ($this->cartService->removeItem($dbCart, $validated['cart_key'])) {
            // Invalidate cart cache
            $this->invalidateCartCache();
            
            return response()->json([
                'success' => true,
                'message' => 'Item removed from cart successfully',
                'cart' => $this->cartService->toArray($dbCart)
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Item not found in cart'
        ], 404);
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
            'discount_amount' => number_format($discountAmount, 2),
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
                'subtotal' => number_format($cartTotals['subtotal'] ?? 0, 2),
                'service_fee' => number_format($cartTotals['service_fee'] ?? 0, 2),
                'addon_charges' => number_format($cartTotals['addon_charges'] ?? 0, 2),
                'tax' => number_format($cartTotals['tax'] ?? 0, 2),
                'vat' => number_format($cartTotals['vat'] ?? 0, 2),
                'discount' => number_format($cartTotals['coupon_discount'] ?? 0, 2),
                'total' => number_format($cartTotals['total'] ?? 0, 2),
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
            $serviceTypeId = ServiceType::where('code', $serviceType)
                ->orWhere('name', $serviceType)
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
}
