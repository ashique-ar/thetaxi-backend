<?php

/**
 * Property-Based Test for Promo Code Validation
 * 
 * **Feature: popup-promo-management, Property 4: Promo Code Validation**
 * **Validates: Requirements 4.1, 4.3, 4.4, 4.5, 4.6**
 * 
 * Property 4: Promo Code Validation
 * *For any* promo code application attempt, THE Cart_Service SHALL reject the code if ANY 
 * of the following conditions are true:
 * - The code does not exist or is inactive
 * - The code is outside its valid date range (before start_date or after end_date)
 * - The cart total is below the minimum_order_amount
 * - The code has reached its total usage_limit
 * - The customer has reached their usage_limit_per_customer for this code
 */

use App\Models\PromoCode;
use Faker\Factory as Faker;
use Carbon\Carbon;

/**
 * Property 4.1: Inactive promo code should always be rejected
 * *For any* promo code with is_active = false, validation SHALL fail with PROMO_CODE_INACTIVE
 */
test('property: inactive promo code always rejected', function () {
    $faker = Faker::create();
    
    // Run 100 iterations with random inactive codes
    for ($i = 0; $i < 100; $i++) {
        // Create a mock promo code object (not persisted)
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $faker->randomElement(['percentage', 'fixed']),
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'minimum_order_amount' => 0,
            'is_active' => false, // Inactive
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        $orderAmount = $faker->randomFloat(2, 100, 10000);
        
        $result = $promoCode->validate($orderAmount);
        
        expect($result['valid'])->toBeFalse();
        expect($result['error_code'])->toBe('PROMO_CODE_INACTIVE');
    }
});

/**
 * Property 4.2: Promo code before start_date should always be rejected
 * *For any* promo code where current time < start_date, validation SHALL fail
 */
test('property: promo code before start date always rejected', function () {
    $faker = Faker::create();
    
    // Run 100 iterations with codes that haven't started yet
    for ($i = 0; $i < 100; $i++) {
        $futureStart = Carbon::now()->addDays($faker->numberBetween(1, 365));
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $faker->randomElement(['percentage', 'fixed']),
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'minimum_order_amount' => 0,
            'start_date' => $futureStart,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        $orderAmount = $faker->randomFloat(2, 100, 10000);
        
        $result = $promoCode->validate($orderAmount);
        
        expect($result['valid'])->toBeFalse();
        expect($result['error_code'])->toBe('PROMO_CODE_NOT_YET_ACTIVE');
    }
});

/**
 * Property 4.3: Promo code after end_date should always be rejected
 * *For any* promo code where current time > end_date, validation SHALL fail with PROMO_CODE_EXPIRED
 */
test('property: promo code after end date always rejected', function () {
    $faker = Faker::create();
    
    // Run 100 iterations with expired codes
    for ($i = 0; $i < 100; $i++) {
        $pastEnd = Carbon::now()->subDays($faker->numberBetween(1, 365));
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $faker->randomElement(['percentage', 'fixed']),
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'minimum_order_amount' => 0,
            'end_date' => $pastEnd,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        $orderAmount = $faker->randomFloat(2, 100, 10000);
        
        $result = $promoCode->validate($orderAmount);
        
        expect($result['valid'])->toBeFalse();
        expect($result['error_code'])->toBe('PROMO_CODE_EXPIRED');
    }
});

/**
 * Property 4.4: Order below minimum should always be rejected
 * *For any* promo code where order_amount < minimum_order_amount, validation SHALL fail
 */
test('property: order below minimum always rejected', function () {
    $faker = Faker::create();
    
    // Run 100 iterations with orders below minimum
    for ($i = 0; $i < 100; $i++) {
        $minimumAmount = $faker->randomFloat(2, 1000, 10000);
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $faker->randomElement(['percentage', 'fixed']),
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'minimum_order_amount' => $minimumAmount,
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        // Order amount is always less than minimum
        $orderAmount = $faker->randomFloat(2, 1, $minimumAmount - 0.01);
        
        $result = $promoCode->validate($orderAmount);
        
        expect($result['valid'])->toBeFalse();
        expect($result['error_code'])->toBe('PROMO_CODE_MINIMUM_NOT_MET');
        expect((float) $result['details']['minimum_required'])->toBe($minimumAmount);
    }
});

/**
 * Property 4.5: Code at usage limit should always be rejected
 * *For any* promo code where usage_count >= usage_limit, validation SHALL fail
 */
test('property: code at usage limit always rejected', function () {
    $faker = Faker::create();
    
    // Run 100 iterations with codes at their usage limit
    for ($i = 0; $i < 100; $i++) {
        $usageLimit = $faker->numberBetween(1, 100);
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $faker->randomElement(['percentage', 'fixed']),
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'minimum_order_amount' => 0,
            'usage_limit' => $usageLimit,
            'usage_count' => $usageLimit, // At limit
            'is_active' => true,
            'usage_limit_per_customer' => 1,
        ]);
        
        $orderAmount = $faker->randomFloat(2, 100, 10000);
        
        $result = $promoCode->validate($orderAmount);
        
        expect($result['valid'])->toBeFalse();
        expect($result['error_code'])->toBe('PROMO_CODE_USAGE_LIMIT_REACHED');
    }
});

/**
 * Property 4.6: Valid promo code should always be accepted
 * *For any* promo code that is active, within date range, meets minimum, and within limits, validation SHALL pass
 */
test('property: valid promo code always accepted', function () {
    $faker = Faker::create();
    
    // Run 100 iterations with valid codes
    for ($i = 0; $i < 100; $i++) {
        $minimumAmount = $faker->randomFloat(2, 100, 500);
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $faker->randomElement(['percentage', 'fixed']),
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'minimum_order_amount' => $minimumAmount,
            'usage_limit' => $faker->numberBetween(100, 1000),
            'usage_count' => 0,
            'usage_limit_per_customer' => $faker->numberBetween(1, 10),
            'start_date' => Carbon::now()->subDays($faker->numberBetween(1, 30)),
            'end_date' => Carbon::now()->addDays($faker->numberBetween(1, 30)),
            'is_active' => true,
        ]);
        
        // Order amount is always above minimum
        $orderAmount = $faker->randomFloat(2, $minimumAmount, $minimumAmount + 10000);
        
        $result = $promoCode->validate($orderAmount);
        
        expect($result['valid'])->toBeTrue();
        expect($result)->toHaveKey('discount');
    }
});

/**
 * Property 4.7: Null usage limit should never cause rejection due to usage limit
 * *For any* promo code with null usage_limit, the code should not be rejected due to usage limit
 */
test('property: null usage limit never causes usage limit rejection', function () {
    $faker = Faker::create();
    
    // Run 100 iterations with codes that have no usage limit
    for ($i = 0; $i < 100; $i++) {
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $faker->randomElement(['percentage', 'fixed']),
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'minimum_order_amount' => 0,
            'usage_limit' => null, // No limit
            'usage_count' => $faker->numberBetween(0, 10000), // Any usage count
            'is_active' => true,
            'usage_limit_per_customer' => 1,
        ]);
        
        $orderAmount = $faker->randomFloat(2, 100, 10000);
        
        $result = $promoCode->validate($orderAmount);
        
        // Should be valid (not rejected due to usage limit)
        expect($result['valid'])->toBeTrue();
    }
});

/**
 * Property 4.8: Null dates should not cause date-related rejection
 * *For any* promo code with null start_date and end_date, the code should not be rejected due to dates
 */
test('property: null dates never cause date rejection', function () {
    $faker = Faker::create();
    
    // Run 100 iterations with codes that have no date restrictions
    for ($i = 0; $i < 100; $i++) {
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $faker->randomElement(['percentage', 'fixed']),
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'minimum_order_amount' => 0,
            'start_date' => null, // No start date
            'end_date' => null, // No end date
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        $orderAmount = $faker->randomFloat(2, 100, 10000);
        
        $result = $promoCode->validate($orderAmount);
        
        // Should be valid (not rejected due to dates)
        expect($result['valid'])->toBeTrue();
    }
});

/**
 * Property 4.9: Zero minimum order amount should accept any positive order
 * *For any* promo code with minimum_order_amount = 0, any positive order amount should pass minimum check
 */
test('property: zero minimum accepts any positive order', function () {
    $faker = Faker::create();
    
    // Run 100 iterations with codes that have zero minimum
    for ($i = 0; $i < 100; $i++) {
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => $faker->randomElement(['percentage', 'fixed']),
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'minimum_order_amount' => 0, // Zero minimum
            'is_active' => true,
            'usage_count' => 0,
            'usage_limit_per_customer' => 1,
        ]);
        
        // Any positive order amount
        $orderAmount = $faker->randomFloat(2, 0.01, 100000);
        
        $result = $promoCode->validate($orderAmount);
        
        // Should be valid
        expect($result['valid'])->toBeTrue();
    }
});
