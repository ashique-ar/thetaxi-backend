<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\User;
use App\Models\Website\WebsiteSetting;
use App\Services\CurrencyService;
use App\Services\PromoCodeService;
use Illuminate\Support\Facades\Log;

class CartService
{
    protected CurrencyService $currencyService;
    protected PromoCodeService $promoCodeService;

    public function __construct(CurrencyService $currencyService, PromoCodeService $promoCodeService)
    {
        $this->currencyService = $currencyService;
        $this->promoCodeService = $promoCodeService;
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
     * Apply a promo code to the cart
     * 
     * Validates the promo code using PromoCodeService, calculates the discount,
     * and applies it to the cart totals.
     *
     * @param Cart $cart The cart to apply the promo code to
     * @param string $code The promo code to apply
     * @param string|null $customerId Optional customer ID for per-customer limit validation
     * @return array Result array with 'success', 'message', and optionally 'discount' and 'promo_code'
     */
    public function applyPromoCode(Cart $cart, string $code, ?string $customerId = null): array
    {
        // Check if a promo code is already applied
        if (!empty($cart->coupon_code)) {
            return [
                'success' => false,
                'error_code' => 'PROMO_CODE_ALREADY_APPLIED',
                'message' => 'A promo code is already applied to this cart. Please remove it first.',
            ];
        }

        // Get the cart subtotal for validation (use LKR values stored in totals)
        $totals = $cart->totals ?? [];
        $subtotal = (float) ($totals['subtotal'] ?? 0);

        // If cart is empty or has no subtotal, reject
        if ($subtotal <= 0) {
            return [
                'success' => false,
                'error_code' => 'CART_EMPTY',
                'message' => 'Cannot apply promo code to an empty cart.',
            ];
        }

        // Validate the promo code
        $validationResult = $this->promoCodeService->validatePromoCode($code, $subtotal, $customerId);

        if (!$validationResult['valid']) {
            return [
                'success' => false,
                'error_code' => $validationResult['error_code'] ?? 'PROMO_CODE_INVALID',
                'message' => $validationResult['message'] ?? 'Invalid promo code.',
                'details' => $validationResult['details'] ?? null,
            ];
        }

        // Get the promo code for additional info
        $promoCode = $this->promoCodeService->getByCode($code);

        if (!$promoCode) {
            return [
                'success' => false,
                'error_code' => 'PROMO_CODE_NOT_FOUND',
                'message' => 'The promo code does not exist.',
            ];
        }

        // Calculate the discount
        $discount = $this->promoCodeService->calculateDiscount($promoCode, $subtotal);

        // Apply the promo code to the cart
        $cart->applyCoupon(strtoupper(trim($code)), $discount);
        $cart->save();

        // Recalculate totals with the discount
        $this->updateTotals($cart);

        Log::info('Promo code applied to cart', [
            'cart_id' => $cart->id,
            'promo_code' => $promoCode->code,
            'discount' => $discount,
            'subtotal' => $subtotal,
            'customer_id' => $customerId,
        ]);

        return [
            'success' => true,
            'message' => 'Promo code applied successfully.',
            'discount' => $discount,
            'promo_code' => [
                'code' => $promoCode->code,
                'name' => $promoCode->name,
                'discount_type' => $promoCode->discount_type,
                'discount_value' => $promoCode->discount_value,
            ],
        ];
    }

    /**
     * Remove the applied promo code from the cart
     * 
     * Removes the promo code and recalculates cart totals without the discount.
     *
     * @param Cart $cart The cart to remove the promo code from
     * @return array Result array with 'success' and 'message'
     */
    public function removePromoCode(Cart $cart): array
    {
        // Check if there's a promo code to remove
        if (empty($cart->coupon_code)) {
            return [
                'success' => false,
                'error_code' => 'NO_PROMO_CODE_APPLIED',
                'message' => 'No promo code is currently applied to this cart.',
            ];
        }

        $removedCode = $cart->coupon_code;
        $removedDiscount = $cart->coupon_discount;

        // Remove the promo code
        $cart->removeCoupon();
        $cart->save();

        // Recalculate totals without the discount
        $this->updateTotals($cart);

        Log::info('Promo code removed from cart', [
            'cart_id' => $cart->id,
            'removed_code' => $removedCode,
            'removed_discount' => $removedDiscount,
        ]);

        return [
            'success' => true,
            'message' => 'Promo code removed successfully.',
            'removed_code' => $removedCode,
            'removed_discount' => $removedDiscount,
        ];
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
                return (float) $lkrPrice;
            } else {
                // Per-day services: multiply by number of days
                $days = is_array($item) ? ($item['days'] ?? 1) : ($item->days ?? 1);
                return (float) $lkrPrice * (int) $days;
            }
        });

        // Calculate service fee dynamically from database settings
        $serviceFee = round($this->calculateServiceFee($subtotal), 2);

        // Calculate addon charges
        $addonCharges = 0;
        foreach ($items as $item) {
            if (is_array($item) && !empty($item['addons'])) {
                foreach ($item['addons'] as $addon) {
                    if (is_array($addon)) {
                        $addonCharges += (float) ($addon['calculated_amount'] ?? 0);
                    }
                }
            }
        }
        $addonCharges = round($addonCharges, 2);

        // Calculate extra km charges
        $extraKmCharges = 0;
        foreach ($items as $item) {
            if (is_array($item) && !empty($item['extra_km'])) {
                $extraKmCharges += (float) ($item['extra_km']['total_cost'] ?? 0);
            }
        }
        $extraKmCharges = round($extraKmCharges, 2);

        // Coupon discount (if any)
        $couponDiscount = round($cart->coupon_discount ?? 0, 2);

        // Calculate tax dynamically from database settings (apply on taxable base after discount)
        $tax = 0;
        if ($this->isTaxEnabled()) {
            $taxRate = $this->getTaxPercentage(); // decimal e.g. 0.18
            $taxableBase = max(0, $subtotal - $couponDiscount) + $serviceFee + $addonCharges + $extraKmCharges;
            $tax = round($taxableBase * $taxRate, 2);
        }

        // Calculate VAT dynamically from database settings
        $vat = 0;
        if ($this->isVatEnabled()) {
            $vatRate = $this->getVatPercentage();
            $vatBase = max(0, $subtotal - $couponDiscount) + $addonCharges + $extraKmCharges;

            // Add service fee to VAT base if configured
            if ($this->isVatAppliedToServiceFee()) {
                $vatBase += $serviceFee;
            }

            $vat = round($vatBase * $vatRate, 2);
        }

        $subtotalRounded = round($subtotal, 2);
        $total = round($subtotalRounded + $serviceFee + $tax + $vat + $addonCharges + $extraKmCharges - $couponDiscount, 2);

        $totalsArray = [
            'subtotal' => round($subtotal, 2),
            'service_fee' => round($serviceFee, 2),
            'addon_charges' => round($addonCharges, 2),
            'extra_km_charges' => round($extraKmCharges, 2),
            'tax' => round($tax, 2),
            'tax_label' => $this->getSettingValue('tax_label', config('booking.tax.label', 'NBT')),
            'vat' => round($vat, 2),
            'vat_label' => $this->getSettingValue('vat_label', config('booking.vat.label', 'VAT')),
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
        if (!$this->isServiceFeeEnabled()) {
            return 0;
        }

        $type = $this->getSettingValue('service_fee_type', config('booking.service_fee.type', null));
        if (!$type) {
            $legacyPercentage = $this->getSettingValue('service_fee_percentage', null);
            $type = $legacyPercentage !== null ? 'percentage' : 'fixed';
        }

        $amount = $this->getSettingValue(
            'service_fee_amount',
            config('booking.service_fee.amount', 0),
            ['service_fee_percentage']
        );
        $minAmount = $this->getSettingValue('service_fee_min_amount', config('booking.service_fee.min_amount', 0));
        $maxAmount = $this->getSettingValue('service_fee_max_amount', config('booking.service_fee.max_amount', null));
        $minAmount = ($minAmount !== null && $minAmount !== '') ? (float) $minAmount : 0;
        $maxAmount = ($maxAmount !== null && $maxAmount !== '') ? (float) $maxAmount : null;

        if ($type === 'percentage') {
            $fee = $subtotal * $this->normalizePercentage($amount);
        } else {
            $fee = (float) $amount;
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
            $item = is_array($item) ? $item : (array) $item;

            // Preserve package service indicators
            $serviceType = $item['service_type'] ?? '';
            $isPackage = ($item['is_package'] ?? false) || in_array($serviceType, ['wedding_hire', 'airport_transfers']);

            // Preserve service package info
            $servicePackageInfo = $item['service_package_info'] ?? null;
            $servicePackageId = $item['service_package_id'] ?? null;

            // Convert prices from LKR (stored) to selected currency
            if (isset($item['price'])) {
                $item['price'] = $this->currencyService->convertFromLKR((float) $item['price'], $selectedCurrency);
                $item['price_lkr'] = (float) ($item['price_lkr'] ?? $item['price']); // Preserve original LKR price
            }

            if (isset($item['total_price'])) {
                $item['total_price'] = $this->currencyService->convertFromLKR((float) $item['total_price'], $selectedCurrency);
                $item['total_price_lkr'] = (float) ($item['total_price_lkr'] ?? $item['total_price']); // Preserve original LKR price
            }

            // Convert extra_km prices if present
            if (isset($item['extra_km']) && is_array($item['extra_km'])) {
                $extraKm = $item['extra_km'];
                $item['extra_km'] = [
                    'km' => $extraKm['km'] ?? 0,
                    'rate_per_km' => $this->currencyService->convertFromLKR((float) ($extraKm['rate_per_km'] ?? 0), $selectedCurrency),
                    'rate_per_km_lkr' => (float) ($extraKm['rate_per_km'] ?? 0),
                    'total_cost' => $this->currencyService->convertFromLKR((float) ($extraKm['total_cost'] ?? 0), $selectedCurrency),
                    'total_cost_lkr' => (float) ($extraKm['total_cost'] ?? 0),
                    'currency' => $selectedCurrency,
                    'added_at' => $extraKm['added_at'] ?? null
                ];
            }

            // Convert distance_details extra_km_price if present
            if (
                isset($item['distance_details']) && is_array($item['distance_details']) &&
                isset($item['distance_details']['extra_km_price'])
            ) {
                $item['distance_details']['extra_km_price'] = $this->currencyService->convertFromLKR(
                    (float) $item['distance_details']['extra_km_price'],
                    $selectedCurrency
                );
            }

            // Add currency and package information
            $item['currency'] = $selectedCurrency;
            $item['is_package'] = $isPackage;
            $item['service_type'] = $serviceType;
            $item['service_package_id'] = $servicePackageId;
            $item['service_package_info'] = $servicePackageInfo;
            $item['currency_symbol'] = getCurrencySymbol($selectedCurrency);

            return $item;
        })
            // Sort items by pickup date (earliest first) for consistent display across the UI
            // NOTE: do NOT call ->values() here because that reindexes associative keys (cart keys)
            // which breaks client-side removal that relies on the original cart_key values.
            ->sortBy(function ($i) {
                // Normalize pickup date - missing dates go to end
                $d = $i['pickup_date'] ?? ($i['from_date'] ?? null);
                return $d ? \Carbon\Carbon::parse($d)->format('Y-m-d H:i:s') : '9999-12-31 23:59:59';
            });
        // Convert totals to selected currency
        $totals = $cart->totals ?? [];
        $convertedTotals = [];

        foreach ($totals as $key => $value) {
            if (is_numeric($value)) {
                $convertedTotals[$key] = $this->currencyService->convertFromLKR((float) $value, $selectedCurrency);
                $convertedTotals[$key . '_lkr'] = (float) $value; // Preserve original LKR value
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
            'coupon_discount' => $this->currencyService->convertFromLKR((float) ($cart->coupon_discount ?? 0), $selectedCurrency),
            'coupon_discount_lkr' => (float) ($cart->coupon_discount ?? 0),
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
                'amount' => (float) $addon->amount,
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
            if (
                ($addon->min_qty && $qty < $addon->min_qty) ||
                ($addon->max_qty && $qty > $addon->max_qty)
            ) {
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

        // Convert addon amounts to the user's selected currency before returning
        $selectedCurrency = $this->currencyService->getSelectedCurrency();

        $addons = $query->whereNull('deleted_at') // Only active (not soft deleted)
            ->select('id', 'name', 'description', 'thumbnail', 'amount', 'rate_type', 'min_qty', 'max_qty')
            ->orderBy('name')
            ->get()
            ->map(function ($addon) use ($selectedCurrency) {
                $amountLkr = (float) ($addon->amount ?? 0);
                $converted = $this->currencyService->convertFromLKR($amountLkr, $selectedCurrency);
                return [
                    'id' => $addon->id,
                    'name' => $addon->name,
                    'description' => $addon->description,
                    'thumbnail' => $addon->thumbnail,
                    'amount' => (float) $converted,
                    'amount_lkr' => $amountLkr,
                    'rate_type' => $addon->rate_type,
                    'min_qty' => $addon->min_qty,
                    'max_qty' => $addon->max_qty,
                    'currency' => $selectedCurrency
                ];
            })->toArray();

        return $addons;
    }

    /**
     * Calculate addon total amount based on rate type
     */
    protected function calculateAddonAmount(\App\Models\Vehicle\VehicleAddon $addon, int $qty): float
    {
        if ($addon->rate_type === 'percentage') {
            // For percentage-based addons, calculate per day
            // This will be adjusted during totals calculation based on item days
            return (float) $addon->amount;
        } else {
            // Flat rate per unit
            return (float) $addon->amount * $qty;
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
            return (float) $lkrPrice * (int) $days;
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

        $serviceFee = round($this->calculateServiceFee($subtotal), 2);

        // Calculate extra km charges (if any)
        $extraKmCharges = 0;
        foreach ($items as $item) {
            if (is_array($item) && !empty($item['extra_km'])) {
                $extraKmCharges += (float) ($item['extra_km']['total_cost'] ?? 0);
            }
        }
        $extraKmCharges = round($extraKmCharges, 2);

        $couponDiscount = round($cart->coupon_discount ?? 0, 2);

        $tax = 0;
        if ($this->isTaxEnabled()) {
            $taxRate = $this->getTaxPercentage();
            $taxableBase = max(0, $subtotal - $couponDiscount) + $addonCharges + $serviceFee + $extraKmCharges;
            $tax = round($taxableBase * $taxRate, 2);
        }

        $vat = 0;
        if ($this->isVatEnabled()) {
            $vatRate = $this->getVatPercentage();
            $vatBase = max(0, $subtotal - $couponDiscount) + $addonCharges + $extraKmCharges;

            if ($this->isVatAppliedToServiceFee()) {
                $vatBase += $serviceFee;
            }

            $vat = round($vatBase * $vatRate, 2);
        }

        $total = round($subtotal + $addonCharges + $serviceFee + $tax + $vat + $extraKmCharges - $couponDiscount, 2);

        $totalsArray = [
            'subtotal' => round($subtotal, 2),
            'addon_charges' => round($addonCharges, 2),
            'service_fee' => round($serviceFee, 2),
            'tax' => round($tax, 2),
            'tax_label' => $this->getSettingValue('tax_label', config('booking.tax.label', 'NBT')),
            'vat' => round($vat, 2),
            'vat_label' => $this->getSettingValue('vat_label', config('booking.vat.label', 'VAT')),
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
     * Get setting value with optional fallback keys.
     */
    protected function getSettingValue(string $settingKey, $default = null, array $fallbackKeys = [])
    {
        $value = $this->getWebsiteSetting($settingKey, null);
        if ($value !== null && $value !== '') {
            return $value;
        }

        foreach ($fallbackKeys as $fallbackKey) {
            $fallbackValue = $this->getWebsiteSetting($fallbackKey, null);
            if ($fallbackValue !== null && $fallbackValue !== '') {
                return $fallbackValue;
            }
        }

        return $default;
    }

    /**
     * Normalize a percentage value coming from config or website settings.
     *
     * Accepts values like '18', '18%', 18, 0.18 and returns decimal (0.18).
     */
    protected function normalizePercentage($value): float
    {
        if (is_string($value)) {
            $value = str_replace('%', '', $value);
            $value = trim($value);
        }
        $val = (float) $value;
        if ($val > 1) {
            // Convert whole-number percentages (e.g. 18) to decimal (0.18)
            $val = $val / 100;
        }
        // Clamp to sensible range [0, 1]
        if ($val < 0) {
            $val = 0;
        }
        if ($val > 1) {
            $val = 1;
        }
        return $val;
    }

    /**
     * Normalize a boolean value coming from config or website settings.
     */
    protected function normalizeBoolean($value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * Get tax percentage from database settings
     */
    protected function getTaxPercentage(): float
    {
        $taxRate = $this->getSettingValue('tax_rate', config('booking.tax.rate', 0.18), ['tax_percentage']);
        return $this->normalizePercentage($taxRate);
    }

    /**
     * Determine if tax is enabled.
     */
    protected function isTaxEnabled(): bool
    {
        $settingValue = $this->getSettingValue('tax_enabled', null);
        return $this->normalizeBoolean($settingValue, (bool) config('booking.tax.enabled', true));
    }

    /**
     * Get service fee percentage from database settings
     */
    protected function getServiceFeePercentage(): float
    {
        $feeRate = $this->getSettingValue(
            'service_fee_percentage',
            config('booking.service_fee.rate', 0)
        );
        return $this->normalizePercentage($feeRate); // Normalize different formats (e.g. 5 or 0.05)
    }

    /**
     * Get VAT percentage from database settings
     */
    protected function getVatPercentage(): float
    {
        $vatRate = $this->getSettingValue('vat_rate', config('booking.vat.rate', 0), ['vat_percentage']);
        return $this->normalizePercentage($vatRate);
    }

    /**
     * Determine if VAT is enabled.
     */
    protected function isVatEnabled(): bool
    {
        $settingValue = $this->getSettingValue('vat_enabled', null);
        return $this->normalizeBoolean($settingValue, (bool) config('booking.vat.enabled', true));
    }

    /**
     * Determine if service fee is enabled.
     */
    protected function isServiceFeeEnabled(): bool
    {
        $settingValue = $this->getSettingValue('service_fee_enabled', null);
        return $this->normalizeBoolean($settingValue, (bool) config('booking.service_fee.enabled', true));
    }

    /**
     * Determine if VAT should apply to service fee.
     */
    protected function isVatAppliedToServiceFee(): bool
    {
        $settingValue = $this->getSettingValue('vat_applies_to_service_fee', null);
        return $this->normalizeBoolean($settingValue, (bool) config('booking.vat.applies_to_service_fee', true));
    }

    /**
     * Get extra km rate for a vehicle group from common rates
     * 
     * @param string $vehicleGroupId
     * @return array|null Returns ['rate' => float, 'currency' => string] or null if not found
     */
    /**
     * Get extra km rate for a vehicle group and optional service type
     * 
     * The extra_km_rate is defined per service type in common rate definitions,
     * then vehicle groups can have specific values assigned.
     * 
     * @param string $vehicleGroupId Vehicle group ID
     * @param string|null $serviceTypeId Optional service type ID for more specific lookup
     * @return array|null Rate info with 'rate', 'currency', 'definition_id', 'definition_name'
     */
    public function getExtraKmRateForVehicleGroup(string $vehicleGroupId, ?string $serviceTypeId = null): ?array
    {
        try {
            // Build query to find the extra_km_rate common rate definition
            $definitionQuery = \App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition::where('code', 'extra_km_rate')
                ->where('is_active', true);

            // If service type is provided, filter by it for more accurate rate
            if ($serviceTypeId) {
                $definitionQuery->where('service_type_id', $serviceTypeId);
            }

            $extraKmDefinition = $definitionQuery->first();

            if (!$extraKmDefinition) {
                \Illuminate\Support\Facades\Log::warning('Extra KM rate definition not found', [
                    'vehicle_group_id' => $vehicleGroupId,
                    'service_type_id' => $serviceTypeId,
                ]);
                return null;
            }

            // Get the rate value for this vehicle group
            $vehicleGroupRate = \App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing::where('vehicle_group_id', $vehicleGroupId)
                ->where('common_rate_definition_id', $extraKmDefinition->id)
                ->where('is_active', true)
                ->first();

            if ($vehicleGroupRate && $vehicleGroupRate->value > 0) {
                \Illuminate\Support\Facades\Log::debug('Extra KM rate found for vehicle group', [
                    'vehicle_group_id' => $vehicleGroupId,
                    'service_type_id' => $serviceTypeId,
                    'rate' => $vehicleGroupRate->value,
                    'definition_id' => $extraKmDefinition->id,
                ]);

                return [
                    'rate' => (float) $vehicleGroupRate->value,
                    'currency' => 'LKR',
                    'definition_id' => $extraKmDefinition->id,
                    'definition_name' => $extraKmDefinition->name,
                    'service_type_id' => $extraKmDefinition->service_type_id,
                ];
            }

            \Illuminate\Support\Facades\Log::warning('Extra KM rate not configured for vehicle group', [
                'vehicle_group_id' => $vehicleGroupId,
                'service_type_id' => $serviceTypeId,
                'definition_id' => $extraKmDefinition->id,
            ]);

            return null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error getting extra km rate', [
                'vehicle_group_id' => $vehicleGroupId,
                'service_type_id' => $serviceTypeId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get KM limits from slab definition for a service type
     * 
     * @param string $serviceTypeId Service type ID
     * @param int $days Number of days for the booking
     * @return array|null KM limit info with 'free_km_per_day', 'free_km_per_package', 'allowed_total_km'
     */
    public function getSlabKmLimits(string $serviceTypeId, int $days = 1): ?array
    {
        try {
            $slabDefinition = \App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition::where('service_type_id', $serviceTypeId)
                ->where('is_active', true)
                ->orderBy('min_days')
                ->first();

            if (!$slabDefinition) {
                return null;
            }

            $result = [];

            if ($slabDefinition->max_km_per_day && $slabDefinition->max_km_per_day > 0) {
                $result['free_km_per_day'] = (float) $slabDefinition->max_km_per_day;
                $result['allowed_total_km'] = (float) ($slabDefinition->max_km_per_day * $days);
                $result['calculation_type'] = 'daily';
            } elseif ($slabDefinition->max_km_per_package && $slabDefinition->max_km_per_package > 0) {
                $result['free_km_per_package'] = (float) $slabDefinition->max_km_per_package;
                $result['allowed_total_km'] = (float) $slabDefinition->max_km_per_package;
                $result['calculation_type'] = 'package';
            }

            return !empty($result) ? $result : null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error getting slab km limits', [
                'service_type_id' => $serviceTypeId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Add extra km purchase to a cart item
     * 
     * @param Cart $cart
     * @param string $cartKey
     * @param int $extraKm Number of extra kilometers to purchase
     * @return bool
     */
    public function addExtraKm(Cart $cart, string $cartKey, int $extraKm): bool
    {
        try {
            $items = $cart->items ?? [];

            if (!isset($items[$cartKey])) {
                return false;
            }

            $vehicleGroupId = $items[$cartKey]['vehicle_group_id'] ?? null;
            if (!$vehicleGroupId) {
                return false;
            }

            // Get service type ID from cart item for accurate rate lookup
            $serviceTypeId = $items[$cartKey]['service_type_data']['id'] ?? null;

            // Get extra km rate for this vehicle group and service type
            $extraKmRate = $this->getExtraKmRateForVehicleGroup($vehicleGroupId, $serviceTypeId);
            if (!$extraKmRate) {
                \Illuminate\Support\Facades\Log::warning('Extra KM rate not configured for vehicle group', [
                    'vehicle_group_id' => $vehicleGroupId,
                    'service_type_id' => $serviceTypeId,
                ]);
                return false;
            }

            // Calculate total extra km cost
            $extraKmCost = $extraKmRate['rate'] * $extraKm;

            // Store extra km purchase in item
            $items[$cartKey]['extra_km'] = [
                'km' => $extraKm,
                'rate_per_km' => $extraKmRate['rate'],
                'total_cost' => $extraKmCost,
                'currency' => $extraKmRate['currency'],
                'added_at' => now()->toIso8601String()
            ];

            $cart->items = $items;
            $cart->save();
            $this->updateTotals($cart);

            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error adding extra km to cart', [
                'error' => $e->getMessage(),
                'cart_key' => $cartKey,
                'extra_km' => $extraKm
            ]);
            return false;
        }
    }

    /**
     * Update extra km quantity in cart item
     * 
     * @param Cart $cart
     * @param string $cartKey
     * @param int $extraKm New number of extra kilometers (0 to remove)
     * @return bool
     */
    public function updateExtraKm(Cart $cart, string $cartKey, int $extraKm): bool
    {
        if ($extraKm <= 0) {
            return $this->removeExtraKm($cart, $cartKey);
        }

        return $this->addExtraKm($cart, $cartKey, $extraKm);
    }

    /**
     * Remove extra km purchase from cart item
     * 
     * @param Cart $cart
     * @param string $cartKey
     * @return bool
     */
    public function removeExtraKm(Cart $cart, string $cartKey): bool
    {
        try {
            $items = $cart->items ?? [];

            if (!isset($items[$cartKey])) {
                return false;
            }

            if (isset($items[$cartKey]['extra_km'])) {
                unset($items[$cartKey]['extra_km']);
            }

            $cart->items = $items;
            $cart->save();
            $this->updateTotals($cart);

            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error removing extra km from cart', [
                'error' => $e->getMessage(),
                'cart_key' => $cartKey
            ]);
            return false;
        }
    }

    /**
     * Get extra km info for a cart item
     * 
     * @param Cart $cart
     * @param string $cartKey
     * @return array|null
     */
    public function getItemExtraKm(Cart $cart, string $cartKey): ?array
    {
        $items = $cart->items ?? [];

        if (!isset($items[$cartKey])) {
            return null;
        }

        return $items[$cartKey]['extra_km'] ?? null;
    }
}
