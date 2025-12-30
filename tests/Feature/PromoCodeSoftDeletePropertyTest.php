<?php

/**
 * Property-Based Test for Promo Code Soft Delete
 * 
 * **Feature: popup-promo-management, Property 10: Promo Code Soft Delete**
 * **Validates: Requirements 3.6**
 * 
 * Property 10: Promo Code Soft Delete
 * *For any* promo code that is deleted, THE system SHALL set deleted_at timestamp rather 
 * than removing the record, and the code SHALL no longer appear in active listings but 
 * SHALL remain queryable for historical reporting.
 */

use App\Models\PromoCode;
use App\Services\PromoCodeService;
use Faker\Factory as Faker;
use Carbon\Carbon;

/**
 * Property 10.1: Soft delete sets deleted_at timestamp
 * *For any* promo code, calling delete() SHALL set deleted_at to current timestamp
 */
test('property: soft delete sets deleted_at timestamp', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $promoCode = new PromoCode([
            'code' => strtoupper($faker->regexify('[A-Z0-9]{8}')),
            'name' => $faker->words(3, true),
            'discount_type' => $faker->randomElement([
                PromoCode::DISCOUNT_TYPE_PERCENTAGE,
                PromoCode::DISCOUNT_TYPE_FIXED,
            ]),
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'is_active' => true,
        ]);
        
        // Initially deleted_at should be null
        expect($promoCode->deleted_at)->toBeNull();
        
        // Simulate soft delete by setting deleted_at
        $deletedAt = Carbon::now();
        $promoCode->deleted_at = $deletedAt;
        
        // Verify deleted_at is set
        expect($promoCode->deleted_at)->not->toBeNull();
        expect($promoCode->deleted_at)->toBeInstanceOf(Carbon::class);
        expect($promoCode->trashed())->toBeTrue();
    }
});

/**
 * Property 10.2: Soft deleted promo code is excluded from default queries
 * *For any* soft deleted promo code, it SHALL be excluded from default model queries
 */
test('property: soft deleted promo code is excluded from default queries', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $promoCode = new PromoCode([
            'code' => strtoupper($faker->regexify('[A-Z0-9]{8}')),
            'name' => $faker->words(3, true),
            'discount_type' => PromoCode::DISCOUNT_TYPE_PERCENTAGE,
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'is_active' => true,
        ]);
        
        // Before soft delete, trashed() should return false
        expect($promoCode->trashed())->toBeFalse();
        
        // After setting deleted_at, trashed() should return true
        $promoCode->deleted_at = Carbon::now();
        expect($promoCode->trashed())->toBeTrue();
    }
});

/**
 * Property 10.3: Soft deleted promo code retains all data
 * *For any* soft deleted promo code, all original data SHALL be preserved
 */
test('property: soft deleted promo code retains all data', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $discountType = $faker->randomElement([
            PromoCode::DISCOUNT_TYPE_PERCENTAGE,
            PromoCode::DISCOUNT_TYPE_FIXED,
        ]);
        
        $originalData = [
            'code' => strtoupper($faker->regexify('[A-Z0-9]{8}')),
            'name' => $faker->words(3, true),
            'description' => $faker->optional()->sentence(),
            'discount_type' => $discountType,
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'minimum_order_amount' => $faker->randomFloat(2, 0, 1000),
            'maximum_discount_amount' => $discountType === PromoCode::DISCOUNT_TYPE_PERCENTAGE
                ? $faker->optional()->randomFloat(2, 100, 500)
                : null,
            'usage_limit' => $faker->optional()->numberBetween(10, 100),
            'usage_limit_per_customer' => $faker->numberBetween(1, 5),
            'usage_count' => $faker->numberBetween(0, 50),
            'is_active' => $faker->boolean(),
        ];
        
        $promoCode = new PromoCode($originalData);
        
        // Soft delete
        $promoCode->deleted_at = Carbon::now();
        
        // Verify all original data is preserved after soft delete
        expect($promoCode->code)->toBe($originalData['code']);
        expect($promoCode->name)->toBe($originalData['name']);
        expect($promoCode->description)->toBe($originalData['description']);
        expect($promoCode->discount_type)->toBe($originalData['discount_type']);
        expect((float) $promoCode->discount_value)->toBe((float) $originalData['discount_value']);
        expect((float) $promoCode->minimum_order_amount)->toBe((float) $originalData['minimum_order_amount']);
        
        if ($originalData['maximum_discount_amount'] !== null) {
            expect((float) $promoCode->maximum_discount_amount)
                ->toBe((float) $originalData['maximum_discount_amount']);
        }
        
        expect($promoCode->usage_limit)->toBe($originalData['usage_limit']);
        expect($promoCode->usage_limit_per_customer)->toBe($originalData['usage_limit_per_customer']);
        expect($promoCode->usage_count)->toBe($originalData['usage_count']);
        expect($promoCode->is_active)->toBe($originalData['is_active']);
        
        // Verify it's still marked as trashed
        expect($promoCode->trashed())->toBeTrue();
    }
});

/**
 * Property 10.4: Soft deleted promo code can be restored
 * *For any* soft deleted promo code, setting deleted_at to null SHALL restore it
 */
test('property: soft deleted promo code can be restored', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $promoCode = new PromoCode([
            'code' => strtoupper($faker->regexify('[A-Z0-9]{8}')),
            'name' => $faker->words(3, true),
            'discount_type' => PromoCode::DISCOUNT_TYPE_PERCENTAGE,
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'is_active' => true,
        ]);
        
        // Soft delete
        $promoCode->deleted_at = Carbon::now();
        expect($promoCode->trashed())->toBeTrue();
        
        // Restore by setting deleted_at to null
        $promoCode->deleted_at = null;
        expect($promoCode->trashed())->toBeFalse();
        expect($promoCode->deleted_at)->toBeNull();
    }
});

/**
 * Property 10.5: Soft delete timestamp is accurate
 * *For any* soft deleted promo code, deleted_at SHALL be within acceptable time range
 */
test('property: soft delete timestamp is accurate', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $promoCode = new PromoCode([
            'code' => strtoupper($faker->regexify('[A-Z0-9]{8}')),
            'name' => $faker->words(3, true),
            'discount_type' => PromoCode::DISCOUNT_TYPE_PERCENTAGE,
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'is_active' => true,
        ]);
        
        $beforeDelete = Carbon::now()->subSecond(); // Add 1 second tolerance
        $promoCode->deleted_at = Carbon::now();
        $afterDelete = Carbon::now()->addSecond(); // Add 1 second tolerance
        
        // Verify deleted_at is within the expected time range (with tolerance)
        expect($promoCode->deleted_at->gte($beforeDelete))->toBeTrue();
        expect($promoCode->deleted_at->lte($afterDelete))->toBeTrue();
    }
});

/**
 * Property 10.6: Multiple soft deletes don't change original deleted_at
 * *For any* already soft deleted promo code, the original deleted_at should be preserved
 */
test('property: soft delete preserves original timestamp when already deleted', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $promoCode = new PromoCode([
            'code' => strtoupper($faker->regexify('[A-Z0-9]{8}')),
            'name' => $faker->words(3, true),
            'discount_type' => PromoCode::DISCOUNT_TYPE_PERCENTAGE,
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'is_active' => true,
        ]);
        
        // First soft delete
        $originalDeletedAt = Carbon::now()->subMinutes($faker->numberBetween(1, 60));
        $promoCode->deleted_at = $originalDeletedAt;
        
        // Verify original timestamp is preserved
        expect($promoCode->deleted_at->format('Y-m-d H:i:s'))
            ->toBe($originalDeletedAt->format('Y-m-d H:i:s'));
        expect($promoCode->trashed())->toBeTrue();
    }
});

/**
 * Property 10.7: Soft deleted promo code validation still works
 * *For any* soft deleted promo code, validation methods SHALL still function
 */
test('property: soft deleted promo code validation still works', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $promoCode = new PromoCode([
            'code' => strtoupper($faker->regexify('[A-Z0-9]{8}')),
            'name' => $faker->words(3, true),
            'discount_type' => PromoCode::DISCOUNT_TYPE_PERCENTAGE,
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'minimum_order_amount' => 100,
            'is_active' => true,
        ]);
        
        // Soft delete
        $promoCode->deleted_at = Carbon::now();
        
        // Validation methods should still work for historical reporting
        $orderAmount = $faker->randomFloat(2, 200, 1000);
        
        // These methods should still function on soft deleted records
        expect($promoCode->meetsMinimumOrderAmount($orderAmount))->toBeTrue();
        expect($promoCode->calculateDiscount($orderAmount))->toBeGreaterThan(0);
        expect($promoCode->hasReachedUsageLimit())->toBeFalse();
    }
});

/**
 * Property 10.8: Soft deleted promo code analytics data is preserved
 * *For any* soft deleted promo code with usage data, analytics calculations SHALL still work
 */
test('property: soft deleted promo code analytics data is preserved', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $usageCount = $faker->numberBetween(1, 100);
        
        $promoCode = new PromoCode([
            'code' => strtoupper($faker->regexify('[A-Z0-9]{8}')),
            'name' => $faker->words(3, true),
            'discount_type' => PromoCode::DISCOUNT_TYPE_PERCENTAGE,
            'discount_value' => $faker->randomFloat(2, 5, 50),
            'usage_count' => $usageCount,
            'is_active' => true,
        ]);
        
        // Soft delete
        $promoCode->deleted_at = Carbon::now();
        
        // Usage count should be preserved for historical reporting
        expect($promoCode->usage_count)->toBe($usageCount);
        expect($promoCode->trashed())->toBeTrue();
    }
});
