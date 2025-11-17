<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Session;

class CartService
{
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

        // Calculate subtotal
        $subtotal = $items->sum(function ($item) {
            return ($item['price'] ?? 0) * ($item['days'] ?? 1);
        });

        $serviceFee = 25.00;
        $tax = $subtotal * 0.18; // 18% tax
        $couponDiscount = $cart->coupon_discount ?? 0;
        $total = $subtotal + $serviceFee + $tax - $couponDiscount;

        $cart->setTotals([
            'subtotal' => round($subtotal, 2),
            'service_fee' => $serviceFee,
            'tax' => round($tax, 2),
            'coupon_discount' => $couponDiscount,
            'total' => round($total, 2)
        ]);
        
        $cart->save();
    }

    /**
     * Get cart as array for response
     */
    public function toArray(Cart $cart): array
    {
        return [
            'id' => $cart->id,
            'items' => $cart->items ?? [],
            'totals' => $cart->totals ?? [],
            'coupon_code' => $cart->coupon_code,
            'coupon_discount' => $cart->coupon_discount,
            'item_count' => $cart->itemCount(),
            'is_empty' => !$cart->hasItems()
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
