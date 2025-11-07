<?php

namespace App\Http\Controllers;

use App\Models\BookingSearch;
use App\Models\Vehicle\VehicleGroup;
use App\Services\BookingSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class CartController extends Controller
{
    public function __construct()
    {
        //
    }
    
    /**
     * Display cart page
     */
    public function index()
    {
        $cart = session()->get('cart', []);
        $cartItems = collect($cart);
        
        // Calculate totals
        $subtotal = $cartItems->sum(function ($item) {
            return ($item['price'] ?? 0) * ($item['days'] ?? 1);
        });
        
        $serviceFee = 25.00;
        $tax = $subtotal * 0.1; // 10% tax
        $discount = session()->get('cart_discount', 0);
        $total = $subtotal + $serviceFee + $tax - $discount;
        
        return view('cart', compact('cartItems', 'subtotal', 'serviceFee', 'tax', 'discount', 'total'));
    }
    
    /**
     * Add item to cart
     */
    public function add(Request $request)
    {
        $validated = $request->validate([
            'vehicle_group_id' => 'required|exists:vehicle_groups,id',
            'pickup_date' => 'required|date',
            'return_date' => 'required|date|after:pickup_date',
            'pickup_location' => 'required|string',
            'return_location' => 'nullable|string',
            'price' => 'required|numeric|min:0'
        ]);
        
        // Get vehicle details
        $vehicleGroup = VehicleGroup::find($validated['vehicle_group_id']);
        
        if (!$vehicleGroup) {
            return redirect()->back()->with('error', 'Vehicle not found.');
        }
        
        // Calculate days
        $pickupDate = \Carbon\Carbon::parse($validated['pickup_date']);
        $returnDate = \Carbon\Carbon::parse($validated['return_date']);
        $days = $pickupDate->diffInDays($returnDate);
        $days = max(1, $days); // Minimum 1 day
        
        // Create cart item
        $cartItem = [
            'vehicle_group_id' => $vehicleGroup->id,
            'name' => $vehicleGroup->name,
            'vehicle_type' => $vehicleGroup->vehicle_type ?? 'Sedan',
            'image' => $vehicleGroup->image_path,
            'price' => $validated['price'],
            'days' => $days,
            'pickup_date' => $validated['pickup_date'],
            'return_date' => $validated['return_date'],
            'pickup_location' => $validated['pickup_location'],
            'return_location' => $validated['return_location'] ?? $validated['pickup_location'],
            'added_at' => now()
        ];
        
        // Add to cart session
        $cart = session()->get('cart', []);
        $cartKey = 'vehicle_' . $vehicleGroup->id . '_' . time();
        $cart[$cartKey] = $cartItem;
        
        session()->put('cart', $cart);
        
        return redirect()->route('cart')->with('success', 'Vehicle added to cart successfully!');
    }
    
    /**
     * Update cart item days (for rental duration)
     */
    public function updateDays(Request $request)
    {
        $validated = $request->validate([
            'cart_key' => 'required|string',
            'days' => 'required|integer|min:1|max:365',
        ]);
        
        $cart = session()->get('cart', []);
        
        if (isset($cart[$validated['cart_key']])) {
            $cart[$validated['cart_key']]['days'] = $validated['days'];
            
            // Update return date based on new days
            $pickupDate = \Carbon\Carbon::parse($cart[$validated['cart_key']]['pickup_date']);
            $newReturnDate = $pickupDate->addDays($validated['days']);
            $cart[$validated['cart_key']]['return_date'] = $newReturnDate->toDateString();
            
            session()->put('cart', $cart);
            
            $itemTotal = $cart[$validated['cart_key']]['price'] * $validated['days'];
            
            return response()->json([
                'success' => true,
                'message' => 'Cart updated successfully',
                'item_total' => number_format($itemTotal, 2),
                'new_return_date' => $newReturnDate->format('M d, Y')
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Item not found in cart'
        ], 404);
    }
    
    /**
     * Update cart item quantity (legacy method)
     */
    public function update(Request $request, string $itemKey)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:10',
        ]);
        
        $cart = session()->get('cart', []);
        
        if (isset($cart[$itemKey])) {
            // For vehicle rentals, quantity usually means days
            $cart[$itemKey]['days'] = $validated['quantity'];
            session()->put('cart', $cart);
            
            return response()->json([
                'success' => true,
                'message' => 'Cart updated successfully'
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Item not found in cart'
        ], 404);
    }
    
    /**
     * Remove item from cart (AJAX)
     */
    public function remove(Request $request)
    {
        $validated = $request->validate([
            'cart_key' => 'required|string'
        ]);
        
        $cart = session()->get('cart', []);
        
        if (isset($cart[$validated['cart_key']])) {
            unset($cart[$validated['cart_key']]);
            session()->put('cart', $cart);
            
            return response()->json([
                'success' => true,
                'message' => 'Item removed from cart successfully'
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
        session()->forget('cart');
        session()->forget('cart_discount');
        session()->forget('applied_coupon');
        
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
        
        // Check if coupon already applied
        if (session()->get('applied_coupon') === $couponCode) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon already applied'
            ]);
        }
        
        $coupon = $validCoupons[$couponCode];
        $cart = session()->get('cart', []);
        
        if (empty($cart)) {
            return response()->json([
                'success' => false,
                'message' => 'Cart is empty'
            ]);
        }
        
        // Calculate subtotal
        $subtotal = collect($cart)->sum(function ($item) {
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
        
        // Store coupon data in session
        session()->put('cart_discount', $discountAmount);
        session()->put('applied_coupon', $couponCode);
        
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
        session()->forget('cart_discount');
        session()->forget('applied_coupon');
        
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
        $cart = session()->get('cart', []);
        $cartItems = collect($cart);
        
        $subtotal = $cartItems->sum(function ($item) {
            return ($item['price'] ?? 0) * ($item['days'] ?? 1);
        });
        
        $serviceFee = 25.00;
        $tax = $subtotal * 0.1;
        $discount = session()->get('cart_discount', 0);
        $total = $subtotal + $serviceFee + $tax - $discount;
        
        return response()->json([
            'success' => true,
            'cart_count' => count($cart),
            'subtotal' => number_format($subtotal, 2),
            'service_fee' => number_format($serviceFee, 2),
            'tax' => number_format($tax, 2),
            'discount' => number_format($discount, 2),
            'total' => number_format($total, 2),
            'applied_coupon' => session()->get('applied_coupon')
        ]);
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
                'return_location' => $item['dropoff_location'] ?? '',
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
}
