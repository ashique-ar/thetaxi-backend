<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website\WebsiteSetting;
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

        // Calculate subtotal using LKR prices from BookingFlowService (already calculated package amounts)
        $subtotal = $items->sum(function ($item) {
            // JSON-decoded items may be stdClass objects or arrays
            // Handle both cases for accessing properties
            $lkrPrice = 0;
            $isPackageService = false;
            $serviceType = '';
            
            if (is_array($item)) {
                $lkrPrice = $item['price_lkr'] ?? $item['price'] ?? 0;
                $serviceType = $item['service_type'] ?? '';
                $isPackageService = $item['is_package'] ?? false;
            } else if (is_object($item)) {
                $lkrPrice = $item->price_lkr ?? $item->price ?? 0;
                $serviceType = $item->service_type ?? '';
                $isPackageService = $item->is_package ?? false;
            }
            
            // Determine if this is a package service (already calculated as total, not per-day)
            $isPackage = $isPackageService || in_array($serviceType, ['wedding_hire', 'airport_transfers']);
            
            if ($isPackage) {
                // Package services: price is already the total amount, don't multiply by days
                return (float)$lkrPrice;
            } else {
                // Per-day services: multiply by number of days
                $days = is_array($item) ? ($item['days'] ?? 1) : ($item->days ?? 1);
                return (float)$lkrPrice * (int)$days;
            }
        });

        // Calculate service fee dynamically from database settings
        $serviceFee = $this->calculateServiceFee($subtotal);
        
        // Calculate tax dynamically from database settings
        $tax = 0;
        if (config('booking.tax.enabled', true)) {
            $taxRate = $this->getTaxPercentage();
            $tax = $subtotal * $taxRate;
        }
        
        // Calculate VAT dynamically from database settings
        $vat = 0;
        if (config('booking.vat.enabled', true)) {
            $vatRate = $this->getVatPercentage();
            $vatBase = $subtotal;
            
            // Add service fee to VAT base if configured
            if (config('booking.vat.applies_to_service_fee', true)) {
                $vatBase += $serviceFee;
            }
            
            $vat = $vatBase * $vatRate;
        }
        
        // Calculate addon charges
        $addonCharges = 0;
        foreach ($items as $item) {
            if (is_array($item) && !empty($item['addons'])) {
                foreach ($item['addons'] as $addon) {
                    if (is_array($addon)) {
                        $addonCharges += (float)($addon['calculated_amount'] ?? 0);
                    }
                }
            }
        }
        
        $couponDiscount = $cart->coupon_discount ?? 0;
        $total = $subtotal + $serviceFee + $tax + $vat + $addonCharges - $couponDiscount;

        $totalsArray = [
            'subtotal' => round($subtotal, 2),
            'service_fee' => round($serviceFee, 2),
            'addon_charges' => round($addonCharges, 2),
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
            
            // Preserve package service indicators
            $serviceType = $item['service_type'] ?? '';
            $isPackage = ($item['is_package'] ?? false) || in_array($serviceType, ['wedding_hire', 'airport_transfers']);
            
            // Convert prices from LKR (stored) to selected currency
            if (isset($item['price'])) {
                $item['price'] = $this->currencyService->convertFromLKR((float)$item['price'], $selectedCurrency);
                $item['price_lkr'] = (float)($item['price_lkr'] ?? $item['price']); // Preserve original LKR price
            }
            
            if (isset($item['total_price'])) {
                $item['total_price'] = $this->currencyService->convertFromLKR((float)$item['total_price'], $selectedCurrency);
                $item['total_price_lkr'] = (float)($item['total_price_lkr'] ?? $item['total_price']); // Preserve original LKR price
            }
            
            // Add currency and package information
            $item['currency'] = $selectedCurrency;
            $item['is_package'] = $isPackage;
            $item['service_type'] = $serviceType;
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

    /**
     * Add addon to a cart item
     */
    public function addAddon(Cart $cart, string $cartKey, string $addonId, int $qty = 1): bool
    {
        try {
            $items = $cart->items ?? [];
            
            if (!isset($items[$cartKey])) {
                return false;
            }

            // Ensure addons array exists
            if (!isset($items[$cartKey]['addons'])) {
                $items[$cartKey]['addons'] = [];
            }

            // Get addon details from database
            $addon = \App\Models\Vehicle\VehicleAddon::find($addonId);
            if (!$addon) {
                return false;
            }

            // Calculate addon amount based on rate type
            $addonAmount = $this->calculateAddonAmount($addon, $qty);

            // Add or update addon in item
            $items[$cartKey]['addons'][$addonId] = [
                'id' => $addon->id,
                'name' => $addon->name,
                'description' => $addon->description,
                'thumbnail' => $addon->thumbnail,
                'qty' => $qty,
                'amount' => (float)$addon->amount,
                'rate_type' => $addon->rate_type,
                'calculated_amount' => $addonAmount,
                'added_at' => now()->toIso8601String()
            ];

            $cart->items = $items;
            $cart->save();
            $this->updateTotals($cart);
            
            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error adding addon to cart', [
                'error' => $e->getMessage(),
                'cart_key' => $cartKey,
                'addon_id' => $addonId
            ]);
            return false;
        }
    }

    /**
     * Remove addon from cart item
     */
    public function removeAddon(Cart $cart, string $cartKey, string $addonId): bool
    {
        try {
            $items = $cart->items ?? [];
            
            if (!isset($items[$cartKey]['addons'][$addonId])) {
                return false;
            }

            unset($items[$cartKey]['addons'][$addonId]);
            
            // Remove empty addons array
            if (empty($items[$cartKey]['addons'])) {
                unset($items[$cartKey]['addons']);
            }

            $cart->items = $items;
            $cart->save();
            $this->updateTotals($cart);
            
            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error removing addon from cart', [
                'error' => $e->getMessage(),
                'cart_key' => $cartKey,
                'addon_id' => $addonId
            ]);
            return false;
        }
    }

    /**
     * Update addon quantity in cart item
     */
    public function updateAddonQty(Cart $cart, string $cartKey, string $addonId, int $qty): bool
    {
        try {
            $items = $cart->items ?? [];
            
            if (!isset($items[$cartKey]['addons'][$addonId])) {
                return false;
            }

            if ($qty <= 0) {
                return $this->removeAddon($cart, $cartKey, $addonId);
            }

            $addon = \App\Models\Vehicle\VehicleAddon::find($addonId);
            if (!$addon) {
                return false;
            }

            // Check qty constraints
            if (($addon->min_qty && $qty < $addon->min_qty) || 
                ($addon->max_qty && $qty > $addon->max_qty)) {
                return false;
            }

            // Recalculate addon amount
            $addonAmount = $this->calculateAddonAmount($addon, $qty);

            $items[$cartKey]['addons'][$addonId]['qty'] = $qty;
            $items[$cartKey]['addons'][$addonId]['calculated_amount'] = $addonAmount;

            $cart->items = $items;
            $cart->save();
            $this->updateTotals($cart);
            
            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error updating addon qty in cart', [
                'error' => $e->getMessage(),
                'cart_key' => $cartKey,
                'addon_id' => $addonId,
                'qty' => $qty
            ]);
            return false;
        }
    }

    /**
     * Get addons for a specific cart item
     */
    public function getItemAddons(Cart $cart, string $cartKey): array
    {
        $items = $cart->items ?? [];
        
        if (!isset($items[$cartKey])) {
            return [];
        }

        return $items[$cartKey]['addons'] ?? [];
    }

    /**
     * Get all available addons for a service type
     */
    public function getAvailableAddons(?string $serviceTypeId = null): array
    {
        $query = \App\Models\Vehicle\VehicleAddon::query();
        
        if ($serviceTypeId) {
            $query->where('service_type_id', $serviceTypeId)->orWhereNull('service_type_id');
        }

        return $query->whereNull('deleted_at') // Only active (not soft deleted)
            ->select('id', 'name', 'description', 'thumbnail', 'amount', 'rate_type', 'min_qty', 'max_qty')
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    /**
     * Calculate addon total amount based on rate type
     */
    protected function calculateAddonAmount(\App\Models\Vehicle\VehicleAddon $addon, int $qty): float
    {
        if ($addon->rate_type === 'percentage') {
            // For percentage-based addons, calculate per day
            // This will be adjusted during totals calculation based on item days
            return (float)$addon->amount;
        } else {
            // Flat rate per unit
            return (float)$addon->amount * $qty;
        }
    }

    /**
     * Update totals including addon amounts
     * Override the existing updateTotals to include addons
     */
    public function updateTotalsWithAddons(Cart $cart): void
    {
        $items = $cart->getItems();
        
        if ($items->isEmpty()) {
            $cart->setTotals([
                'subtotal' => 0,
                'addon_charges' => 0,
                'service_fee' => 0,
                'tax' => 0,
                'vat' => 0,
                'coupon_discount' => 0,
                'total' => 0
            ]);
            $cart->save();
            return;
        }

        // Calculate subtotal from items
        $subtotal = $items->sum(function ($item) {
            $lkrPrice = 0;
            if (is_array($item)) {
                $lkrPrice = $item['price_lkr'] ?? $item['price'] ?? 0;
            } else if (is_object($item)) {
                $lkrPrice = $item->price_lkr ?? $item->price ?? 0;
            }
            $days = is_array($item) ? ($item['days'] ?? 1) : ($item->days ?? 1);
            return (float)$lkrPrice * (int)$days;
        });

        // Calculate addon charges
        $addonCharges = $items->sum(function ($item) {
            if (!isset($item['addons'])) {
                return 0;
            }
            
            $itemAddons = is_array($item['addons']) ? $item['addons'] : [];
            return collect($itemAddons)->sum(function ($addon) use ($item) {
                // For percentage-based addons, calculate based on item price and days
                if ($addon['rate_type'] === 'percentage') {
                    $itemPrice = is_array($item) ? ($item['price_lkr'] ?? 0) : ($item->price_lkr ?? 0);
                    $days = is_array($item) ? ($item['days'] ?? 1) : ($item->days ?? 1);
                    $percentage = $addon['amount'];
                    return ($itemPrice * $days) * ($percentage / 100);
                }
                // For flat rate addons
                return $addon['calculated_amount'] ?? 0;
            });
        });

        $serviceFee = $this->calculateServiceFee($subtotal);
        
        $tax = 0;
        if (config('booking.tax.enabled', true)) {
            $taxRate = config('booking.tax.rate', 0.025);
            $tax = $subtotal * $taxRate;
        }
        
        $vat = 0;
        if (config('booking.vat.enabled', true)) {
            $vatRate = config('booking.vat.rate', 0.18);
            $vatBase = $subtotal + $addonCharges;
            
            if (config('booking.vat.applies_to_service_fee', true)) {
                $vatBase += $serviceFee;
            }
            
            $vat = $vatBase * $vatRate;
        }
        
        $couponDiscount = $cart->coupon_discount ?? 0;
        $total = $subtotal + $addonCharges + $serviceFee + $tax + $vat - $couponDiscount;

        $totalsArray = [
            'subtotal' => round($subtotal, 2),
            'addon_charges' => round($addonCharges, 2),
            'service_fee' => round($serviceFee, 2),
            'tax' => round($tax, 2),
            'tax_label' => config('booking.tax.label', 'NBT'),
            'vat' => round($vat, 2),
            'vat_label' => config('booking.vat.label', 'VAT'),
            'coupon_discount' => round($couponDiscount, 2),
            'total' => round($total, 2)
        ];

        \Illuminate\Support\Facades\Log::info('CartService: Totals with addons calculated', [
            'cart_id' => $cart->id,
            'items_count' => $items->count(),
            'subtotal' => $subtotal,
            'addon_charges' => $addonCharges,
            'service_fee' => $serviceFee,
            'tax' => $tax,
            'vat' => $vat,
            'total' => $total
        ]);

        $cart->setTotals($totalsArray);
        $cart->save();
    }

    /**
     * Get website setting value with fallback to config
     */
    protected function getWebsiteSetting(string $settingKey, $default = null)
    {
        try {
            // First try to get from database
            $setting = WebsiteSetting::where('type', $settingKey)->value('value');
            if ($setting !== null) {
                return $setting;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to fetch website setting: $settingKey", [
                'error' => $e->getMessage()
            ]);
        }
        
        // Fallback to default or config value
        return $default;
    }

    /**
     * Get tax percentage from database settings
     */
    protected function getTaxPercentage(): float
    {
        $taxRate = $this->getWebsiteSetting('tax_percentage', config('booking.tax.rate', 0.1));
        return (float)$taxRate / 100; // Convert percentage to decimal
    }

    /**
     * Get service fee percentage from database settings
     */
    protected function getServiceFeePercentage(): float
    {
        $feeRate = $this->getWebsiteSetting('service_fee_percentage', config('booking.service_fee.rate', 0.05));
        return (float)$feeRate / 100; // Convert percentage to decimal
    }

    /**
     * Get VAT percentage from database settings
     */
    protected function getVatPercentage(): float
    {
        $vatRate = $this->getWebsiteSetting('vat_percentage', config('booking.vat.rate', 0.18));
        return (float)$vatRate / 100; // Convert percentage to decimal
    }
}

