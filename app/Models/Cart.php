<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cart extends BaseModel
{

    protected $fillable = [
        'customer_id',
        'user_id',
        'items',
        'totals',
        'coupon_code',
        'coupon_discount',
        'status',
        'session_id',
        'last_activity_at'
    ];

    protected $casts = [
        'items' => 'json',
        'totals' => 'json',
        'coupon_discount' => 'decimal:2',
        'last_activity_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Get the customer associated with the cart
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the user associated with the cart
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if cart has items
     */
    public function hasItems(): bool
    {
        return !empty($this->items) && count($this->items) > 0;
    }

    /**
     * Get count of items in cart
     */
    public function itemCount(): int
    {
        return count($this->items ?? []);
    }

    /**
     * Get cart items as collection
     */
    public function getItems()
    {
        return collect($this->items ?? []);
    }

    /**
     * Add item to cart
     */
    public function addItem(array $item, string $cartKey): void
    {
        $items = $this->items ?? [];
        $items[$cartKey] = $item;
        $this->items = $items;
        $this->last_activity_at = now();
    }

    /**
     * Remove item from cart by key
     */
    public function removeItem(string $cartKey): bool
    {
        $items = $this->items ?? [];
        
        if (isset($items[$cartKey])) {
            unset($items[$cartKey]);
            $this->items = $items;
            $this->last_activity_at = now();
            return true;
        }
        
        return false;
    }

    /**
     * Update item in cart
     */
    public function updateItem(string $cartKey, array $updates): bool
    {
        $items = $this->items ?? [];
        
        if (isset($items[$cartKey])) {
            $items[$cartKey] = array_merge($items[$cartKey], $updates);
            $this->items = $items;
            $this->last_activity_at = now();
            return true;
        }
        
        return false;
    }

    /**
     * Clear all items from cart
     */
    public function clearItems(): void
    {
        $this->items = [];
        $this->coupon_code = null;
        $this->coupon_discount = 0;
        $this->totals = null;
        $this->last_activity_at = now();
    }

    /**
     * Set totals
     */
    public function setTotals(array $totals): void
    {
        $this->totals = $totals;
        $this->last_activity_at = now();
    }

    /**
     * Get item by key
     */
    public function getItem(string $cartKey): ?array
    {
        return $this->items[$cartKey] ?? null;
    }

    /**
     * Apply coupon
     */
    public function applyCoupon(string $couponCode, float $discount): void
    {
        $this->coupon_code = $couponCode;
        $this->coupon_discount = $discount;
        $this->last_activity_at = now();
    }

    /**
     * Remove coupon
     */
    public function removeCoupon(): void
    {
        $this->coupon_code = null;
        $this->coupon_discount = 0;
        $this->last_activity_at = now();
    }

    /**
     * Mark as abandoned
     */
    public function markAbandoned(): void
    {
        $this->status = 'abandoned';
        $this->last_activity_at = now();
    }

    /**
     * Mark as checked out
     */
    public function markCheckedOut(): void
    {
        $this->status = 'checked_out';
        $this->last_activity_at = now();
    }
}
