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
                'vat' => 0,
                'coupon_discount' => 0,
                'total' => 0
            ]);
            $cart->save();
            return;
        }

        // Calculate subtotal using LKR prices (stored in database)
        $subtotal = $items->sum(function ($item) {
            // JSON-decoded items may be stdClass objects or arrays
            // Handle both cases for accessing properties
            $lkrPrice = 0;
            if (is_array($item)) {
                $lkrPrice = $item['price_lkr'] ?? $item['price'] ?? 0;
            } else if (is_object($item)) {
                $lkrPrice = $item->price_lkr ?? $item->price ?? 0;
            }
            $days = is_array($item) ? ($item['days'] ?? 1) : ($item->days ?? 1);
            return (float)$lkrPrice * (int)$days;
        });

        // Calculate service fee dynamically from config
        $serviceFee = $this->calculateServiceFee($subtotal);
        
        // Calculate tax (NBT) dynamically from config
        $tax = 0;
        if (config('booking.tax.enabled', true)) {
            $taxRate = config('booking.tax.rate', 0.025);
            $tax = $subtotal * $taxRate;
        }
        
        // Calculate VAT dynamically from config
        $vat = 0;
        if (config('booking.vat.enabled', true)) {
            $vatRate = config('booking.vat.rate', 0.18);
            $vatBase = $subtotal;
            
            // Add service fee to VAT base if configured
            if (config('booking.vat.applies_to_service_fee', true)) {
                $vatBase += $serviceFee;
            }
            
            $vat = $vatBase * $vatRate;
        }
        
        $couponDiscount = $cart->coupon_discount ?? 0;
        $total = $subtotal + $serviceFee + $tax + $vat - $couponDiscount;

        $totalsArray = [
            'subtotal' => round($subtotal, 2),
            'service_fee' => round($serviceFee, 2),
            'tax' => round($tax, 2),
            'tax_label' => config('booking.tax.label', 'NBT'),
            'vat' => round($vat, 2),
            'vat_label' => config('booking.vat.label', 'VAT'),
            'coupon_discount' => round($couponDiscount, 2),
            'total' => round($total, 2)
        ];

        \Illuminate\Support\Facades\Log::info('CartService: Totals calculated', [
            'cart_id' => $cart->id,
            'items_count' => $items->count(),
            'subtotal_raw' => $subtotal,
            'service_fee_raw' => $serviceFee,
            'tax_raw' => $tax,
            'vat_raw' => $vat,
            'total_raw' => $total,
            'calculated_totals' => $totalsArray
        ]);

        $cart->setTotals($totalsArray);
        
        $cart->save();
    }

    /**
     * Calculate service fee based on configuration
     */
    protected function calculateServiceFee(float $subtotal): float
    {
        if (!config('booking.service_fee.enabled', true)) {
            return 0;
        }

        $type = config('booking.service_fee.type', 'fixed');
        $amount = config('booking.service_fee.amount', 750.00);
        $minAmount = config('booking.service_fee.min', 0);
        $maxAmount = config('booking.service_fee.max', null);

        if ($type === 'percentage') {
            $fee = $subtotal * ($amount / 100);
        } else {
            $fee = $amount;
        }

        // Apply min/max constraints
        if ($minAmount > 0 && $fee < $minAmount) {
            $fee = $minAmount;
        }

        if ($maxAmount !== null && $fee > $maxAmount) {
            $fee = $maxAmount;
        }

        return $fee;
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
