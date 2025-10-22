<?php

namespace App\Http\Controllers;

use App\Models\BookingSearch;
use App\Models\Vehicle\VehicleGroup;
use App\Services\BookingSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class CartController extends Controller
{
    protected BookingSearchService $searchService;
    
    public function __construct(BookingSearchService $searchService)
    {
        $this->searchService = $searchService;
    }
    
    /**
     * Display cart page
     */
    public function index()
    {
        $cart = session()->get('cart', []);
        $cartItems = [];
        $total = 0;
        
        foreach ($cart as $item) {
            $search = $this->searchService->getSearch($item['search_id']);
            $vehicleGroup = VehicleGroup::with([
                'grade', 'make', 'model', 'transmission', 
                'fuelType', 'category', 'class'
            ])->find($item['group_id']);
            
            if ($search && $vehicleGroup) {
                $cartItems[] = [
                    'search' => $search,
                    'vehicle_group' => $vehicleGroup,
                    'pricing' => $item['pricing'] ?? [],
                    'quantity' => $item['quantity'] ?? 1,
                ];
                
                $total += ($item['pricing']['total_amount'] ?? 0) * ($item['quantity'] ?? 1);
            }
        }
        
        return view('cart', compact('cartItems', 'total'));
    }
    
    /**
     * Add item to cart
     */
    public function add(Request $request)
    {
        $validated = $request->validate([
            'group_id' => 'required|uuid|exists:vehicle_groups,id',
            'search_id' => 'required|uuid|exists:booking_searches,id',
            'quantity' => 'integer|min:1|max:10',
        ]);
        
        $cart = session()->get('cart', []);
        $itemKey = $validated['group_id'] . '_' . $validated['search_id'];
        
        // Get search and calculate pricing
        $search = $this->searchService->getSearch($validated['search_id']);
        $vehicleGroup = VehicleGroup::find($validated['group_id']);
        
        if (!$search || !$vehicleGroup) {
            return redirect()->back()->with('error', 'Invalid vehicle or search data.');
        }
        
        // Get pricing (you may want to recalculate here)
        $results = $this->searchService->searchVehicleGroups($search);
        $pricing = null;
        
        foreach ($results as $result) {
            if ($result['vehicle_group']->id === $validated['group_id']) {
                $pricing = $result['pricing'];
                break;
            }
        }
        
        if (!$pricing) {
            return redirect()->back()->with('error', 'Could not calculate pricing for this vehicle.');
        }
        
        // Add or update cart item
        if (isset($cart[$itemKey])) {
            $cart[$itemKey]['quantity'] += $validated['quantity'] ?? 1;
        } else {
            $cart[$itemKey] = [
                'group_id' => $validated['group_id'],
                'search_id' => $validated['search_id'],
                'quantity' => $validated['quantity'] ?? 1,
                'pricing' => $pricing,
                'added_at' => now(),
            ];
        }
        
        session()->put('cart', $cart);
        
        return redirect()->route('cart')->with('success', 'Vehicle added to cart successfully!');
    }
    
    /**
     * Update cart item quantity
     */
    public function update(Request $request, string $itemKey)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:10',
        ]);
        
        $cart = session()->get('cart', []);
        
        if (isset($cart[$itemKey])) {
            $cart[$itemKey]['quantity'] = $validated['quantity'];
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
     * Remove item from cart
     */
    public function remove(Request $request, string $itemKey)
    {
        $cart = session()->get('cart', []);
        
        if (isset($cart[$itemKey])) {
            unset($cart[$itemKey]);
            session()->put('cart', $cart);
            
            return redirect()->route('cart')->with('success', 'Item removed from cart.');
        }
        
        return redirect()->route('cart')->with('error', 'Item not found in cart.');
    }
    
    /**
     * Clear entire cart
     */
    public function clear()
    {
        session()->forget('cart');
        return redirect()->route('cart')->with('success', 'Cart cleared successfully.');
    }
    
    /**
     * Proceed to checkout
     */
    public function checkout()
    {
        $cart = session()->get('cart', []);
        
        if (empty($cart)) {
            return redirect()->route('cart')->with('error', 'Your cart is empty.');
        }
        
        return redirect()->route('checkout');
    }
}
