<?php

/**
 * Property-Based Test for Promo Code Discount Calculation
 * 
 * **Feature: popup-promo-management, Property 5: Promo Code Discount Calculation**
 * **Validates: Requirements 4.2**
 * 
 * Property 5: Promo Code Discount Calculation
 * *For any* valid promo code with discount_type "percentage", THE Cart_Service SHALL calculate 
 * the discount as `min(order_amount * (discount_value / 100), maximum_discount_amount)`. 
 * *For any* valid promo code with discount_type "fixed", THE Cart_Service SHALL calculate 
 * the discount as `min(discount_value, order_amount)`.
 */

use App\Models\PromoCode;
use Faker\Factory as Faker;

/**
 * Property 5.1: Percentage discount calculation formula
 * *For any* percentage promo code without max cap, discount = order_amount * (discount_value / 100)
 */
test('property: percentage discount without cap follows formula', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $discountValue = $faker->randomFloat(2, 1, 100); // 1% to 100%
        $orderAmount = $faker->randomFloat(2, 100, 100000);
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => 'percentage',
            'discount_value' => $discountValue,
            'maximum_discount_amount' => null, // No cap
            'minimum_order_amount' => 0,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        $expectedDiscount = round($orderAmount * ($discountValue / 100), 2);
        $actualDiscount = $promoCode->calculateDiscount($orderAmount);
        
        expect($actualDiscount)->toBe($expectedDiscount);
    }
});

/**
 * Property 5.2: Percentage discount with maximum cap
 * *For any* percentage promo code with max cap, discount = min(calculated, max_cap)
 */
test('property: percentage discount respects maximum cap', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $discountValue = $faker->randomFloat(2, 10, 50); // 10% to 50%
        $maxDiscount = $faker->randomFloat(2, 100, 1000);
        $orderAmount = $faker->randomFloat(2, 1000, 100000);
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => 'percentage',
            'discount_value' => $discountValue,
            'maximum_discount_amount' => $maxDiscount,
            'minimum_order_amount' => 0,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        $calculatedDiscount = $orderAmount * ($discountValue / 100);
        $expectedDiscount = round(min($calculatedDiscount, $maxDiscount), 2);
        $actualDiscount = $promoCode->calculateDiscount($orderAmount);
        
        expect($actualDiscount)->toBe($expectedDiscount);
        // Discount should never exceed the cap
        expect($actualDiscount)->toBeLessThanOrEqual($maxDiscount);
    }
});

/**
 * Property 5.3: Fixed discount calculation formula
 * *For any* fixed promo code, discount = min(discount_value, order_amount)
 */
test('property: fixed discount follows formula', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $discountValue = $faker->randomFloat(2, 50, 5000);
        $orderAmount = $faker->randomFloat(2, 100, 10000);
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => 'fixed',
            'discount_value' => $discountValue,
            'minimum_order_amount' => 0,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        $expectedDiscount = round(min($discountValue, $orderAmount), 2);
        $actualDiscount = $promoCode->calculateDiscount($orderAmount);
        
        expect($actualDiscount)->toBe($expectedDiscount);
    }
});

/**
 * Property 5.4: Fixed discount never exceeds order amount
 * *For any* fixed promo code, the discount should never exceed the order amount
 */
test('property: fixed discount never exceeds order amount', function () {
    $faker = Faker::create();
    
    // Run 100 iterations with discount larger than order
    for ($i = 0; $i < 100; $i++) {
        $orderAmount = $faker->randomFloat(2, 10, 500);
        $discountValue = $orderAmount + $faker->randomFloat(2, 100, 5000); // Always larger than order
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => 'fixed',
            'discount_value' => $discountValue,
            'minimum_order_amount' => 0,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        $actualDiscount = $promoCode->calculateDiscount($orderAmount);
        
        // Discount should equal order amount (capped)
        expect($actualDiscount)->toBe(round($orderAmount, 2));
        expect($actualDiscount)->toBeLessThanOrEqual($orderAmount);
    }
});

/**
 * Property 5.5: Discount is always non-negative
 * *For any* promo code and positive order amount, the discount should be non-negative
 */
test('property: discount is always non-negative', function () {
    $faker = Faker::create();
    
    // Run 100 iterations with various configurations
    for ($i = 0; $i < 100; $i++) {
        $discountType = $faker->randomElement(['percentage', 'fixed']);
        $discountValue = $faker->randomFloat(2, 0.01, 100);
        $orderAmount = $faker->randomFloat(2, 0.01, 100000);
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'maximum_discount_amount' => $faker->optional()->randomFloat(2, 100, 1000),
            'minimum_order_amount' => 0,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        $actualDiscount = $promoCode->calculateDiscount($orderAmount);
        
        expect($actualDiscount)->toBeGreaterThanOrEqual(0);
    }
});

/**
 * Property 5.6: Percentage discount is proportional to order amount (without cap)
 * *For any* percentage promo code without cap, doubling the order doubles the discount
 */
test('property: percentage discount is proportional to order amount', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $discountValue = $faker->randomFloat(2, 1, 50);
        $orderAmount = $faker->randomFloat(2, 100, 10000);
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => 'percentage',
            'discount_value' => $discountValue,
            'maximum_discount_amount' => null, // No cap
            'minimum_order_amount' => 0,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        $discount1 = $promoCode->calculateDiscount($orderAmount);
        $discount2 = $promoCode->calculateDiscount($orderAmount * 2);
        
        // Doubling order should double discount (within rounding tolerance)
        expect(abs($discount2 - ($discount1 * 2)))->toBeLessThanOrEqual(0.02);
    }
});

/**
 * Property 5.7: Fixed discount is constant regardless of order amount (when order >= discount)
 * *For any* fixed promo code where order >= discount_value, the discount equals discount_value
 */
test('property: fixed discount is constant when order exceeds discount value', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $discountValue = $faker->randomFloat(2, 50, 500);
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => 'fixed',
            'discount_value' => $discountValue,
            'minimum_order_amount' => 0,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        // Test with multiple order amounts all greater than discount
        $orderAmounts = [
            $discountValue + 1,
            $discountValue * 2,
            $discountValue * 10,
            $faker->randomFloat(2, $discountValue, $discountValue + 10000),
        ];
        
        foreach ($orderAmounts as $orderAmount) {
            $actualDiscount = $promoCode->calculateDiscount($orderAmount);
            expect($actualDiscount)->toBe(round($discountValue, 2));
        }
    }
});

/**
 * Property 5.8: 100% percentage discount equals order amount (without cap)
 * *For any* 100% percentage promo code without cap, discount equals order amount
 */
test('property: 100 percent discount equals order amount', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $orderAmount = $faker->randomFloat(2, 100, 100000);
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => 'percentage',
            'discount_value' => 100, // 100%
            'maximum_discount_amount' => null, // No cap
            'minimum_order_amount' => 0,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        $actualDiscount = $promoCode->calculateDiscount($orderAmount);
        
        expect($actualDiscount)->toBe(round($orderAmount, 2));
    }
});
