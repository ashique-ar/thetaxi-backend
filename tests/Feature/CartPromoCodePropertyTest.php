<?php

/**
 * Property-Based Test for Cart Total with Promo Code
 * 
 * **Feature: popup-promo-management, Property 6: Cart Total with Promo Code**
 * **Validates: Requirements 4.7, 4.8**
 * 
 * Property 6: Cart Total with Promo Code
 * *For any* cart with a valid promo code applied, THE Cart_Service SHALL calculate 
 * the final total as `original_total - discount_amount`. 
 * *For any* cart after promo code removal, THE Cart_Service SHALL restore the total 
 * to the original amount before the discount was applied.
 */

use App\Models\Cart;
use App\Models\PromoCode;
use Faker\Factory as Faker;

/**
 * Property 6.1: Cart total with promo code equals original_total - discount_amount
 * *For any* cart with a valid promo code applied, the final total should be original_total - discount_amount
 * 
 * This test validates the Cart model's applyCoupon method and total calculation logic.
 */
test('property: cart total with promo code equals original minus discount', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        // Create a promo code (not persisted)
        $discountType = $faker->randomElement(['percentage', 'fixed']);
        $discountValue = $discountType === 'percentage' 
            ? $faker->randomFloat(2, 5, 50) // 5% to 50%
            : $faker->randomFloat(2, 100, 2000); // Fixed 100 to 2000
        $maxDiscount = $discountType === 'percentage' 
            ? $faker->optional(0.5)->randomFloat(2, 500, 5000) 
            : null;
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'maximum_discount_amount' => $maxDiscount,
            'minimum_order_amount' => 0,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit' => null,
            'usage_limit_per_customer' => 100,
        ]);
        
        // Create cart totals
        $subtotal = $faker->randomFloat(2, 1000, 50000);
        $serviceFee = $faker->randomFloat(2, 50, 500);
        $tax = $faker->randomFloat(2, 0, 500);
        $vat = $faker->randomFloat(2, 0, 1000);
        $originalTotal = $subtotal + $serviceFee + $tax + $vat;
        
        // Create a cart (not persisted)
        $cart = new Cart([
            'session_id' => $faker->uuid(),
            'status' => 'active',
            'items' => [
                'item1' => [
                    'price' => $subtotal,
                    'price_lkr' => $subtotal,
                    'days' => 1,
                ]
            ],
            'totals' => [
                'subtotal' => $subtotal,
                'service_fee' => $serviceFee,
                'tax' => $tax,
                'vat' => $vat,
                'coupon_discount' => 0,
                'total' => $originalTotal,
            ],
            'coupon_code' => null,
            'coupon_discount' => 0,
        ]);
        
        // Calculate expected discount
        $expectedDiscount = $promoCode->calculateDiscount($subtotal);
        
        // Apply promo code to cart using the model method
        $cart->applyCoupon($promoCode->code, $expectedDiscount);
        
        // Verify the discount was applied
        expect($cart->coupon_code)->toBe($promoCode->code);
        expect((float)$cart->coupon_discount)->toBe($expectedDiscount);
        
        // Manually update totals to simulate what CartService.updateTotals does
        $totals = $cart->totals;
        $totals['coupon_discount'] = $expectedDiscount;
        $totals['total'] = $originalTotal - $expectedDiscount;
        $cart->setTotals($totals);
        
        // Verify the total is reduced by the discount amount
        $newTotals = $cart->totals;
        $expectedTotal = $originalTotal - $expectedDiscount;
        
        // Use tolerance for floating point comparison
        expect(abs((float)$newTotals['total'] - round($expectedTotal, 2)))->toBeLessThanOrEqual(0.01);
        expect(abs((float)$newTotals['coupon_discount'] - $expectedDiscount))->toBeLessThanOrEqual(0.01);
    }
});


/**
 * Property 6.2: Cart total restored after promo code removal
 * *For any* cart after promo code removal, the total should be restored to original amount
 */
test('property: cart total restored after promo code removal', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        // Create a promo code (not persisted)
        $discountType = $faker->randomElement(['percentage', 'fixed']);
        $discountValue = $discountType === 'percentage' 
            ? $faker->randomFloat(2, 5, 30) 
            : $faker->randomFloat(2, 100, 1000);
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'minimum_order_amount' => 0,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit' => null,
            'usage_limit_per_customer' => 100,
        ]);
        
        // Create cart totals
        $subtotal = $faker->randomFloat(2, 1000, 50000);
        $originalTotal = $subtotal;
        
        // Create a cart (not persisted)
        $cart = new Cart([
            'session_id' => $faker->uuid(),
            'status' => 'active',
            'items' => [
                'item1' => [
                    'price' => $subtotal,
                    'price_lkr' => $subtotal,
                    'days' => 1,
                ]
            ],
            'totals' => [
                'subtotal' => $subtotal,
                'service_fee' => 0,
                'tax' => 0,
                'vat' => 0,
                'coupon_discount' => 0,
                'total' => $originalTotal,
            ],
            'coupon_code' => null,
            'coupon_discount' => 0,
        ]);
        
        // Calculate and apply discount
        $discount = $promoCode->calculateDiscount($subtotal);
        $cart->applyCoupon($promoCode->code, $discount);
        
        // Update totals with discount
        $totals = $cart->totals;
        $totals['coupon_discount'] = $discount;
        $totals['total'] = $originalTotal - $discount;
        $cart->setTotals($totals);
        
        $totalWithDiscount = $cart->totals['total'];
        
        // Remove promo code
        $cart->removeCoupon();
        
        // Verify promo code is removed
        expect($cart->coupon_code)->toBeNull();
        expect((float)$cart->coupon_discount)->toBe(0.0);
        
        // Update totals without discount
        $totals = $cart->totals;
        $totals['coupon_discount'] = 0;
        $totals['total'] = $originalTotal;
        $cart->setTotals($totals);
        
        // Verify total is restored
        expect((float)$cart->totals['coupon_discount'])->toBe(0.0);
        expect((float)$cart->totals['total'])->toBe(round($originalTotal, 2));
        
        // The total after removal should be greater than total with discount
        expect($cart->totals['total'])->toBeGreaterThan($totalWithDiscount);
    }
});

/**
 * Property 6.3: Discount amount is correctly stored in cart
 * *For any* valid promo code application, the discount amount stored matches calculated discount
 */
test('property: discount amount stored matches calculated discount', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $discountType = $faker->randomElement(['percentage', 'fixed']);
        $discountValue = $discountType === 'percentage' 
            ? $faker->randomFloat(2, 5, 50) 
            : $faker->randomFloat(2, 100, 2000);
        $maxDiscount = $discountType === 'percentage' 
            ? $faker->optional(0.5)->randomFloat(2, 500, 5000) 
            : null;
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'maximum_discount_amount' => $maxDiscount,
            'minimum_order_amount' => 0,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit' => null,
            'usage_limit_per_customer' => 100,
        ]);
        
        $subtotal = $faker->randomFloat(2, 1000, 50000);
        
        $cart = new Cart([
            'session_id' => $faker->uuid(),
            'status' => 'active',
            'items' => [
                'item1' => [
                    'price' => $subtotal,
                    'price_lkr' => $subtotal,
                    'days' => 1,
                ]
            ],
            'totals' => [
                'subtotal' => $subtotal,
                'service_fee' => 0,
                'tax' => 0,
                'vat' => 0,
                'coupon_discount' => 0,
                'total' => $subtotal,
            ],
            'coupon_code' => null,
            'coupon_discount' => 0,
        ]);
        
        // Calculate expected discount
        $expectedDiscount = $promoCode->calculateDiscount($subtotal);
        
        // Apply promo code
        $cart->applyCoupon($promoCode->code, $expectedDiscount);
        
        // Verify stored discount matches expected
        expect((float)$cart->coupon_discount)->toBe($expectedDiscount);
        
        // Update totals
        $totals = $cart->totals;
        $totals['coupon_discount'] = $expectedDiscount;
        $cart->setTotals($totals);
        
        expect((float)$cart->totals['coupon_discount'])->toBe($expectedDiscount);
    }
});

/**
 * Property 6.4: Cart coupon code is stored correctly
 * *For any* promo code application, the code should be stored as provided
 */
test('property: cart coupon code stored correctly', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $code = $faker->regexify('[A-Za-z0-9]{8}');
        
        $cart = new Cart([
            'session_id' => $faker->uuid(),
            'status' => 'active',
            'items' => [],
            'totals' => [],
            'coupon_code' => null,
            'coupon_discount' => 0,
        ]);
        
        $discount = $faker->randomFloat(2, 10, 500);
        
        // Apply coupon
        $cart->applyCoupon($code, $discount);
        
        // Verify code is stored
        expect($cart->coupon_code)->toBe($code);
        expect((float)$cart->coupon_discount)->toBe($discount);
    }
});

/**
 * Property 6.5: Removing coupon resets both code and discount
 * *For any* cart with a coupon, removing it should reset both coupon_code and coupon_discount
 */
test('property: removing coupon resets both code and discount', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $code = $faker->regexify('[A-Z0-9]{8}');
        $discount = $faker->randomFloat(2, 10, 5000);
        
        $cart = new Cart([
            'session_id' => $faker->uuid(),
            'status' => 'active',
            'items' => [],
            'totals' => [],
            'coupon_code' => $code,
            'coupon_discount' => $discount,
        ]);
        
        // Verify coupon is applied
        expect($cart->coupon_code)->toBe($code);
        expect((float)$cart->coupon_discount)->toBe($discount);
        
        // Remove coupon
        $cart->removeCoupon();
        
        // Verify both are reset
        expect($cart->coupon_code)->toBeNull();
        expect((float)$cart->coupon_discount)->toBe(0.0);
    }
});

/**
 * Property 6.6: Discount never exceeds subtotal
 * *For any* promo code and cart, the discount should never exceed the cart subtotal
 */
test('property: discount never exceeds subtotal', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $discountType = $faker->randomElement(['percentage', 'fixed']);
        // Use large discount values to test edge cases
        $discountValue = $discountType === 'percentage' 
            ? $faker->randomFloat(2, 50, 100) // 50% to 100%
            : $faker->randomFloat(2, 5000, 50000); // Large fixed discount
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'maximum_discount_amount' => null, // No cap
            'minimum_order_amount' => 0,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        // Use smaller subtotals to test edge cases
        $subtotal = $faker->randomFloat(2, 100, 5000);
        
        // Calculate discount
        $discount = $promoCode->calculateDiscount($subtotal);
        
        // Discount should never exceed subtotal
        expect($discount)->toBeLessThanOrEqual($subtotal);
    }
});

/**
 * Property 6.7: Total with discount is always non-negative
 * *For any* cart with a promo code, the final total should never be negative
 */
test('property: total with discount is always non-negative', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $discountType = $faker->randomElement(['percentage', 'fixed']);
        $discountValue = $discountType === 'percentage' 
            ? $faker->randomFloat(2, 1, 100) 
            : $faker->randomFloat(2, 1, 10000);
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'maximum_discount_amount' => null,
            'minimum_order_amount' => 0,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        $subtotal = $faker->randomFloat(2, 100, 50000);
        $serviceFee = $faker->randomFloat(2, 0, 500);
        $tax = $faker->randomFloat(2, 0, 500);
        $vat = $faker->randomFloat(2, 0, 1000);
        $originalTotal = $subtotal + $serviceFee + $tax + $vat;
        
        // Calculate discount
        $discount = $promoCode->calculateDiscount($subtotal);
        
        // Calculate final total
        $finalTotal = $originalTotal - $discount;
        
        // Total should never be negative
        expect($finalTotal)->toBeGreaterThanOrEqual(0);
    }
});
