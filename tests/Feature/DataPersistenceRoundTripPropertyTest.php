<?php

/**
 * Property-Based Test for Data Persistence Round-Trip
 * 
 * **Feature: popup-promo-management, Property 9: Data Persistence Round-Trip**
 * **Validates: Requirements 6.1, 6.2**
 * 
 * Property 9: Data Persistence Round-Trip
 * *For any* popup or promo code created through the API, retrieving the same record by ID 
 * SHALL return data equivalent to what was submitted (accounting for server-generated 
 * fields like id, created_at, updated_at).
 * 
 * Note: These tests verify the model's data handling and attribute casting without 
 * actual database persistence, following the pattern of other property tests in this codebase.
 */

use App\Models\Website\Popup;
use App\Models\PromoCode;
use Faker\Factory as Faker;
use Carbon\Carbon;

/**
 * Property 9.1: Popup model correctly stores and retrieves all attributes
 * *For any* popup data, the model SHALL correctly store and retrieve all attributes
 */
test('property: popup model correctly stores and retrieves all attributes', function () {
    $faker = Faker::create();
    
    // Run 100 iterations with random popup data
    for ($i = 0; $i < 100; $i++) {
        $startDate = $faker->optional(0.5)->dateTimeBetween('-1 month', '+1 month');
        $endDate = $startDate ? $faker->optional(0.5)->dateTimeBetween($startDate, '+2 months') : null;
        
        // Generate random popup data
        $inputData = [
            'title' => $faker->sentence(3),
            'content' => $faker->paragraph(2),
            'image' => $faker->optional(0.7)->imageUrl(),
            'cta_text' => $faker->optional(0.7)->words(2, true),
            'cta_link' => $faker->optional(0.7)->url(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'display_frequency' => $faker->randomElement([
                Popup::FREQUENCY_ALWAYS,
                Popup::FREQUENCY_ONCE_PER_SESSION,
                Popup::FREQUENCY_ONCE_PER_DAY,
            ]),
            'target_pages' => $faker->randomElements([
                Popup::TARGET_ALL,
                Popup::TARGET_HOMEPAGE,
                Popup::TARGET_CHECKOUT,
            ], $faker->numberBetween(1, 3)),
            'priority' => $faker->numberBetween(0, 100),
            'is_active' => $faker->boolean(80),
        ];
        
        // Create popup model (not persisted)
        $popup = new Popup($inputData);
        
        // Verify all attributes are correctly stored
        expect($popup->title)->toBe($inputData['title']);
        expect($popup->content)->toBe($inputData['content']);
        expect($popup->image)->toBe($inputData['image']);
        expect($popup->cta_text)->toBe($inputData['cta_text']);
        expect($popup->cta_link)->toBe($inputData['cta_link']);
        expect($popup->display_frequency)->toBe($inputData['display_frequency']);
        expect($popup->target_pages)->toBe($inputData['target_pages']);
        expect($popup->priority)->toBe($inputData['priority']);
        expect($popup->is_active)->toBe($inputData['is_active']);
        
        // Verify date casting works correctly
        if ($inputData['start_date']) {
            expect($popup->start_date)->toBeInstanceOf(Carbon::class);
        } else {
            expect($popup->start_date)->toBeNull();
        }
        
        if ($inputData['end_date']) {
            expect($popup->end_date)->toBeInstanceOf(Carbon::class);
        } else {
            expect($popup->end_date)->toBeNull();
        }
    }
});

/**
 * Property 9.2: Promo code model correctly stores and retrieves all attributes
 * *For any* promo code data, the model SHALL correctly store and retrieve all attributes
 */
test('property: promo code model correctly stores and retrieves all attributes', function () {
    $faker = Faker::create();
    
    // Run 100 iterations with random promo code data
    for ($i = 0; $i < 100; $i++) {
        $discountType = $faker->randomElement([
            PromoCode::DISCOUNT_TYPE_PERCENTAGE,
            PromoCode::DISCOUNT_TYPE_FIXED,
        ]);
        
        $startDate = $faker->optional(0.5)->dateTimeBetween('-1 month', '+1 month');
        $endDate = $startDate ? $faker->optional(0.5)->dateTimeBetween($startDate, '+2 months') : null;
        
        // Generate random promo code data
        $inputData = [
            'code' => strtoupper($faker->unique()->regexify('[A-Z0-9]{8}')),
            'name' => $faker->words(3, true),
            'description' => $faker->optional(0.7)->sentence(),
            'discount_type' => $discountType,
            'discount_value' => $discountType === PromoCode::DISCOUNT_TYPE_PERCENTAGE
                ? $faker->randomFloat(2, 1, 100)
                : $faker->randomFloat(2, 10, 5000),
            'minimum_order_amount' => $faker->optional(0.6)->randomFloat(2, 100, 5000) ?? 0,
            'maximum_discount_amount' => $discountType === PromoCode::DISCOUNT_TYPE_PERCENTAGE
                ? $faker->optional(0.5)->randomFloat(2, 100, 2000)
                : null,
            'usage_limit' => $faker->optional(0.6)->numberBetween(10, 1000),
            'usage_limit_per_customer' => $faker->numberBetween(1, 5),
            'usage_count' => 0,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_active' => $faker->boolean(80),
        ];
        
        // Create promo code model (not persisted)
        $promoCode = new PromoCode($inputData);
        
        // Verify all attributes are correctly stored
        expect($promoCode->code)->toBe($inputData['code']);
        expect($promoCode->name)->toBe($inputData['name']);
        expect($promoCode->description)->toBe($inputData['description']);
        expect($promoCode->discount_type)->toBe($inputData['discount_type']);
        expect((float) $promoCode->discount_value)->toBe((float) $inputData['discount_value']);
        expect((float) $promoCode->minimum_order_amount)->toBe((float) $inputData['minimum_order_amount']);
        
        if ($inputData['maximum_discount_amount'] !== null) {
            expect((float) $promoCode->maximum_discount_amount)
                ->toBe((float) $inputData['maximum_discount_amount']);
        } else {
            expect($promoCode->maximum_discount_amount)->toBeNull();
        }
        
        expect($promoCode->usage_limit)->toBe($inputData['usage_limit']);
        expect($promoCode->usage_limit_per_customer)->toBe($inputData['usage_limit_per_customer']);
        expect($promoCode->usage_count)->toBe($inputData['usage_count']);
        expect($promoCode->is_active)->toBe($inputData['is_active']);
        
        // Verify date casting works correctly
        if ($inputData['start_date']) {
            expect($promoCode->start_date)->toBeInstanceOf(Carbon::class);
        } else {
            expect($promoCode->start_date)->toBeNull();
        }
        
        if ($inputData['end_date']) {
            expect($promoCode->end_date)->toBeInstanceOf(Carbon::class);
        } else {
            expect($promoCode->end_date)->toBeNull();
        }
    }
});

/**
 * Property 9.3: Popup model fill method correctly handles all fillable attributes
 * *For any* popup data, the fill method SHALL correctly update all fillable attributes
 */
test('property: popup model fill method correctly handles all fillable attributes', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        // Create initial popup
        $popup = new Popup([
            'title' => 'Initial Title',
            'content' => 'Initial Content',
            'is_active' => true,
        ]);
        
        // Generate new random data for update
        $updateData = [
            'title' => $faker->sentence(4),
            'content' => $faker->paragraph(3),
            'priority' => $faker->numberBetween(0, 100),
            'is_active' => $faker->boolean(),
            'display_frequency' => $faker->randomElement([
                Popup::FREQUENCY_ALWAYS,
                Popup::FREQUENCY_ONCE_PER_SESSION,
                Popup::FREQUENCY_ONCE_PER_DAY,
            ]),
        ];
        
        // Fill popup with new data
        $popup->fill($updateData);
        
        // Verify updated data is correctly stored
        expect($popup->title)->toBe($updateData['title']);
        expect($popup->content)->toBe($updateData['content']);
        expect($popup->priority)->toBe($updateData['priority']);
        expect($popup->is_active)->toBe($updateData['is_active']);
        expect($popup->display_frequency)->toBe($updateData['display_frequency']);
    }
});

/**
 * Property 9.4: Promo code model fill method correctly handles all fillable attributes
 * *For any* promo code data, the fill method SHALL correctly update all fillable attributes
 */
test('property: promo code model fill method correctly handles all fillable attributes', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        // Create initial promo code
        $promoCode = new PromoCode([
            'code' => 'INITIAL123',
            'name' => 'Initial Name',
            'discount_type' => PromoCode::DISCOUNT_TYPE_PERCENTAGE,
            'discount_value' => 10,
            'is_active' => true,
        ]);
        
        // Generate new random data for update
        $newDiscountType = $faker->randomElement([
            PromoCode::DISCOUNT_TYPE_PERCENTAGE,
            PromoCode::DISCOUNT_TYPE_FIXED,
        ]);
        
        $updateData = [
            'name' => $faker->words(4, true),
            'discount_type' => $newDiscountType,
            'discount_value' => $newDiscountType === PromoCode::DISCOUNT_TYPE_PERCENTAGE
                ? $faker->randomFloat(2, 1, 100)
                : $faker->randomFloat(2, 10, 5000),
            'minimum_order_amount' => $faker->randomFloat(2, 0, 1000),
            'is_active' => $faker->boolean(),
        ];
        
        // Fill promo code with new data
        $promoCode->fill($updateData);
        
        // Verify updated data is correctly stored
        expect($promoCode->name)->toBe($updateData['name']);
        expect($promoCode->discount_type)->toBe($updateData['discount_type']);
        expect((float) $promoCode->discount_value)->toBe((float) $updateData['discount_value']);
        expect((float) $promoCode->minimum_order_amount)->toBe((float) $updateData['minimum_order_amount']);
        expect($promoCode->is_active)->toBe($updateData['is_active']);
    }
});

/**
 * Property 9.5: Popup target_pages array casting works correctly
 * *For any* array of target pages, the model SHALL correctly cast to/from JSON
 */
test('property: popup target_pages array casting works correctly', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $targetPages = $faker->randomElements([
            Popup::TARGET_ALL,
            Popup::TARGET_HOMEPAGE,
            Popup::TARGET_CHECKOUT,
        ], $faker->numberBetween(1, 3));
        
        $popup = new Popup([
            'title' => $faker->sentence(),
            'content' => $faker->paragraph(),
            'target_pages' => $targetPages,
        ]);
        
        // Verify array is correctly stored and retrieved
        expect($popup->target_pages)->toBeArray();
        expect($popup->target_pages)->toBe($targetPages);
        
        // Verify each element is present
        foreach ($targetPages as $page) {
            expect(in_array($page, $popup->target_pages))->toBeTrue();
        }
    }
});

/**
 * Property 9.6: Promo code decimal casting works correctly
 * *For any* decimal values, the model SHALL correctly cast to decimal:2
 */
test('property: promo code decimal casting works correctly', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $discountValue = $faker->randomFloat(4, 1, 100); // 4 decimal places
        $minimumOrderAmount = $faker->randomFloat(4, 0, 5000);
        $maximumDiscountAmount = $faker->randomFloat(4, 100, 2000);
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => PromoCode::DISCOUNT_TYPE_PERCENTAGE,
            'discount_value' => $discountValue,
            'minimum_order_amount' => $minimumOrderAmount,
            'maximum_discount_amount' => $maximumDiscountAmount,
        ]);
        
        // Verify decimal values are correctly cast (should be rounded to 2 decimal places)
        expect((float) $promoCode->discount_value)->toBe(round($discountValue, 2));
        expect((float) $promoCode->minimum_order_amount)->toBe(round($minimumOrderAmount, 2));
        expect((float) $promoCode->maximum_discount_amount)->toBe(round($maximumDiscountAmount, 2));
    }
});

/**
 * Property 9.7: Popup boolean casting works correctly
 * *For any* boolean value, the model SHALL correctly cast is_active
 */
test('property: popup boolean casting works correctly', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $isActive = $faker->boolean();
        
        $popup = new Popup([
            'title' => $faker->sentence(),
            'content' => $faker->paragraph(),
            'is_active' => $isActive,
        ]);
        
        // Verify boolean is correctly stored
        expect($popup->is_active)->toBe($isActive);
        expect($popup->is_active)->toBeBool();
    }
});

/**
 * Property 9.8: Promo code boolean casting works correctly
 * *For any* boolean value, the model SHALL correctly cast is_active
 */
test('property: promo code boolean casting works correctly', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $isActive = $faker->boolean();
        
        $promoCode = new PromoCode([
            'code' => $faker->regexify('[A-Z0-9]{8}'),
            'name' => $faker->words(3, true),
            'discount_type' => PromoCode::DISCOUNT_TYPE_PERCENTAGE,
            'discount_value' => 10,
            'is_active' => $isActive,
        ]);
        
        // Verify boolean is correctly stored
        expect($promoCode->is_active)->toBe($isActive);
        expect($promoCode->is_active)->toBeBool();
    }
});
