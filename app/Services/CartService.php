<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\User;
use App\Services\CurrencyService;
use Illuminate\Support\Facades\Session;

class CartService
{
    protected CurrencyService $currencyService;

    public function __construct(CurrencyService $currencyService)
    {
        $this->currencyService = $currencyService;
    }
    /**
     * Get or create cart for current user/customer/session
     */
    public function getOrCreateCart(): Cart
    {
        $user = auth()->user();
        
        // Try to find existing active cart
        if ($user) {
            $cart = Cart::where('user_id', $user->id)
                ->where('status', 'active')
                ->latest('last_activity_at')
                ->first();
            
            if ($cart) {
                return $cart;
            }
        }
        
        // Check for session-based cart for guests
        $sessionId = session()->getId();
        if ($sessionId) {
            $cart = Cart::where('session_id', $sessionId)
                ->where('status', 'active')
                ->first();
            
            if ($cart) {
                return $cart;
            }
        }
        
        // Create new cart
        return $this->createCart($user);
    }

    /**
     * Create a new cart
     */
    public function createCart(?User $user = null): Cart
    {
        $cart = new Cart();
        $cart->user_id = $user?->id;
        $cart->session_id = session()->getId();
        $cart->status = 'active';
        $cart->items = [];
        $cart->save();
        
        return $cart;
    }

    /**
     * Get cart by ID
     */
    public function getCart(string $cartId): ?Cart
    {
        return Cart::find($cartId);
    }

    /**
     * Add item to cart
     */
    public function addItem(Cart $cart, array $item, string $cartKey): void
    {
        $cart->addItem($item, $cartKey);
        $cart->save();
        $this->updateTotals($cart);
    }

    /**
     * Remove item from cart
     */
    public function removeItem(Cart $cart, string $cartKey): bool
    {
        $removed = $cart->removeItem($cartKey);
        if ($removed) {
            $cart->save();
            $this->updateTotals($cart);
        }
        return $removed;
    }

    /**
     * Update item in cart
     */
    public function updateItem(Cart $cart, string $cartKey, array $updates): bool
    {
        $updated = $cart->updateItem($cartKey, $updates);
        if ($updated) {
            $cart->save();
            $this->updateTotals($cart);
        }
        return $updated;
    }

    /**
     * Clear cart
     */
    public function clearCart(Cart $cart): void
    {
        $cart->clearItems();
        $cart->save();
    }

    /**
     * Remove coupon from cart
     */
    public function removeCoupon(Cart $cart): void
    {
        $cart->removeCoupon();
        $cart->save();
        $this->updateTotals($cart);
    }

    /**
     * Apply coupon to cart
     */
    public function applyCoupon(Cart $cart, string $couponCode, float $discount): void
    {
        $cart->applyCoupon($couponCode, $discount);
        $cart->save();
        $this->updateTotals($cart);
    }

    /**
     * Calculate and update cart totals
     */
    public function updateTotals(Cart $cart): void
    {
        $items = $cart->getItems();
        
        if ($items->isEmpty()) {
            $cart->setTotals([
                'subtotal' => 0,
                'service_fee' => 0,
                'tax' => 0,
                'coupon_discount' => 0,
                'total' => 0
            ]);
            $cart->save();
            return;
        }

        // Calculate subtotal using LKR prices (stored in database)
        $subtotal = $items->sum(function ($item) {
            // Use LKR price if available, otherwise use regular price (should be LKR)
            $lkrPrice = $item['price_lkr'] ?? $item['price'] ?? 0;
            return $lkrPrice * ($item['days'] ?? 1);
        });

        // Store all amounts in LKR for consistency
        $serviceFeeBase = 750.00; // Base service fee in LKR (25 USD equivalent)
        $taxRate = 0.1; // 10% tax
        $tax = $subtotal * $taxRate;
        $couponDiscount = $cart->coupon_discount ?? 0;
        $total = $subtotal + $serviceFeeBase + $tax - $couponDiscount;

        $cart->setTotals([
            'subtotal' => round($subtotal, 2),
            'service_fee' => round($serviceFeeBase, 2),
            'tax' => round($tax, 2),
            'coupon_discount' => $couponDiscount,
            'total' => round($total, 2)
        ]);
        
        $cart->save();
    }

    /**
     * Get cart as array for response with currency conversion
     */
    public function toArray(Cart $cart): array
    {
        $selectedCurrency = $this->currencyService->getSelectedCurrency();
        $items = $cart->items ?? [];
        
        // Convert item prices to selected currency
        $convertedItems = collect($items)->map(function ($item) use ($selectedCurrency) {
            $item = is_array($item) ? $item : (array)$item;
            
            // Convert prices from LKR (stored) to selected currency
            if (isset($item['price'])) {
                $item['price'] = $this->currencyService->convertFromLKR((float)$item['price'], $selectedCurrency);
                $item['price_lkr'] = (float)($item['price_lkr'] ?? $item['price']); // Preserve original LKR price
            }
            
            if (isset($item['total_price'])) {
                $item['total_price'] = $this->currencyService->convertFromLKR((float)$item['total_price'], $selectedCurrency);
                $item['total_price_lkr'] = (float)($item['total_price_lkr'] ?? $item['total_price']); // Preserve original LKR price
            }
            
            // Add currency information
            $item['currency'] = $selectedCurrency;
            $item['currency_symbol'] = getCurrencySymbol($selectedCurrency);
            
            return $item;
        })->toArray();
        
        // Convert totals to selected currency
        $totals = $cart->totals ?? [];
        $convertedTotals = [];
        
        foreach ($totals as $key => $value) {
            if (is_numeric($value)) {
                $convertedTotals[$key] = $this->currencyService->convertFromLKR((float)$value, $selectedCurrency);
                $convertedTotals[$key . '_lkr'] = (float)$value; // Preserve original LKR value
            } else {
                $convertedTotals[$key] = $value;
            }
        }
        
        $convertedTotals['currency'] = $selectedCurrency;
        $convertedTotals['currency_symbol'] = getCurrencySymbol($selectedCurrency);
        
        return [
            'id' => $cart->id,
            'items' => $convertedItems,
            'totals' => $convertedTotals,
            'coupon_code' => $cart->coupon_code,
            'coupon_discount' => $this->currencyService->convertFromLKR((float)($cart->coupon_discount ?? 0), $selectedCurrency),
            'coupon_discount_lkr' => (float)($cart->coupon_discount ?? 0),
            'item_count' => $cart->itemCount(),
            'is_empty' => !$cart->hasItems(),
            'currency' => $selectedCurrency,
            'currency_symbol' => getCurrencySymbol($selectedCurrency)
        ];
    }

    /**
     * Convert session cart to database cart (migration helper)
     */
    public function migrateFromSession(Cart $cart): void
    {
        $sessionCart = session()->get('cart', []);
        $sessionDiscount = session()->get('cart_discount', 0);
        $sessionCoupon = session()->get('applied_coupon', null);

        if (!empty($sessionCart)) {
            $cart->items = $sessionCart;
            
            if ($sessionCoupon) {
                $cart->coupon_code = $sessionCoupon;
                $cart->coupon_discount = $sessionDiscount;
            }

            $this->updateTotals($cart);
        }
    }

    /**
     * Mark cart as checked out
     */
    public function markAsCheckedOut(Cart $cart): void
    {
        $cart->markCheckedOut();
        $cart->save();
        
        // Clear session cart
        session()->forget(['cart', 'cart_discount', 'applied_coupon']);
    }

    /**
     * Delete abandoned carts (older than 30 days)
     */
    public function deleteAbandonedCarts(): int
    {
        return Cart::where('status', 'active')
            ->where('last_activity_at', '<', now()->subDays(30))
            ->delete();
    }

    /**
     * Get cart statistics
     */
    public function getStatistics(): array
    {
        $activeCarts = Cart::where('status', 'active')->count();
        $totalValue = Cart::where('status', 'active')
            ->selectRaw('COALESCE(SUM(JSON_EXTRACT(totals, "$.total")), 0) as total')
            ->value('total') ?? 0;
        
        $checkedOut = Cart::where('status', 'checked_out')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return [
            'active_carts' => $activeCarts,
            'total_value' => $totalValue,
            'checkouts_this_week' => $checkedOut
        ];
    }
}
