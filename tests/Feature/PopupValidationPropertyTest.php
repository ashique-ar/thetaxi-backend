<?php

/**
 * Property-Based Test for Popup Validation
 * 
 * **Feature: popup-promo-management, Property 1: Popup Validation Rules**
 * **Validates: Requirements 1.4**
 * 
 * Property 1: Popup Validation Rules
 * *For any* popup data submitted for creation or update, if required fields (title, content) 
 * are missing or if end_date is before start_date, THE Popup_Manager SHALL reject the 
 * submission with appropriate validation errors.
 */

use App\Services\PopupService;
use Illuminate\Validation\ValidationException;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->popupService = new PopupService();
    $this->faker = Faker::create();
});

/**
 * Property 1.1: Missing title should always cause validation failure
 * *For any* popup data without a title, validation SHALL fail with a title error
 */
test('property: missing title always causes validation failure', function () {
    // Run iterations with random data
    for ($i = 0; $i < 50; $i++) {
        $data = [
            'title' => '', // Empty title
            'content' => $this->faker->paragraph(),
            'start_date' => $this->faker->dateTimeBetween('now', '+1 month'),
            'end_date' => $this->faker->dateTimeBetween('+1 month', '+2 months'),
            'display_frequency' => $this->faker->randomElement(['always', 'once_per_session', 'once_per_day']),
            'priority' => $this->faker->numberBetween(0, 100),
        ];

        try {
            $this->popupService->validatePopupData($data);
            $this->fail('Expected ValidationException was not thrown for empty title');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('title');
        }
    }
});

/**
 * Property 1.2: Missing content should always cause validation failure
 * *For any* popup data without content, validation SHALL fail with a content error
 */
test('property: missing content always causes validation failure', function () {
    // Run iterations with random data
    for ($i = 0; $i < 50; $i++) {
        $data = [
            'title' => $this->faker->sentence(),
            'content' => '', // Empty content
            'start_date' => $this->faker->dateTimeBetween('now', '+1 month'),
            'end_date' => $this->faker->dateTimeBetween('+1 month', '+2 months'),
            'display_frequency' => $this->faker->randomElement(['always', 'once_per_session', 'once_per_day']),
            'priority' => $this->faker->numberBetween(0, 100),
        ];

        try {
            $this->popupService->validatePopupData($data);
            $this->fail('Expected ValidationException was not thrown for empty content');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('content');
        }
    }
});

/**
 * Property 1.3: End date before start date should always cause validation failure
 * *For any* popup data where end_date < start_date, validation SHALL fail with an end_date error
 */
test('property: end date before start date always causes validation failure', function () {
    // Run iterations with random data
    for ($i = 0; $i < 50; $i++) {
        // Generate a random start date
        $startDate = $this->faker->dateTimeBetween('+1 week', '+2 months');
        
        // Generate an end date that is BEFORE the start date
        $endDate = (clone $startDate)->modify('-' . $this->faker->numberBetween(1, 30) . ' days');

        $data = [
            'title' => $this->faker->sentence(),
            'content' => $this->faker->paragraph(),
            'start_date' => $startDate->format('Y-m-d H:i:s'),
            'end_date' => $endDate->format('Y-m-d H:i:s'),
            'display_frequency' => $this->faker->randomElement(['always', 'once_per_session', 'once_per_day']),
            'priority' => $this->faker->numberBetween(0, 100),
        ];

        try {
            $this->popupService->validatePopupData($data);
            $this->fail('Expected ValidationException was not thrown for end_date before start_date');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('end_date');
        }
    }
});

/**
 * Property 1.4: Valid popup data should always pass validation
 * *For any* popup data with valid title, content, and valid date range, validation SHALL pass
 */
test('property: valid popup data always passes validation', function () {
    // Run iterations with random valid data
    for ($i = 0; $i < 50; $i++) {
        // Generate a random start date
        $startDate = $this->faker->dateTimeBetween('now', '+1 month');
        
        // Generate an end date that is AFTER or EQUAL to the start date
        $endDate = (clone $startDate)->modify('+' . $this->faker->numberBetween(0, 60) . ' days');

        $data = [
            'title' => $this->faker->sentence(),
            'content' => $this->faker->paragraph(),
            'image' => $this->faker->optional()->imageUrl(),
            'cta_text' => $this->faker->optional()->words(3, true),
            'cta_link' => $this->faker->optional()->url(),
            'start_date' => $startDate->format('Y-m-d H:i:s'),
            'end_date' => $endDate->format('Y-m-d H:i:s'),
            'display_frequency' => $this->faker->randomElement(['always', 'once_per_session', 'once_per_day']),
            'target_pages' => $this->faker->randomElements(['all', 'homepage', 'checkout'], $this->faker->numberBetween(1, 3)),
            'priority' => $this->faker->numberBetween(0, 100),
            'is_active' => $this->faker->boolean(),
        ];

        // Should not throw any exception
        $result = $this->popupService->validatePopupData($data);
        expect($result)->toBe($data);
    }
});

/**
 * Property 1.5: Both missing title and content should cause validation failure with both errors
 * *For any* popup data without both title and content, validation SHALL fail with both errors
 */
test('property: missing both title and content causes validation failure with both errors', function () {
    // Run iterations with random data
    for ($i = 0; $i < 50; $i++) {
        $data = [
            'title' => '', // Empty title
            'content' => '', // Empty content
            'start_date' => $this->faker->dateTimeBetween('now', '+1 month'),
            'end_date' => $this->faker->dateTimeBetween('+1 month', '+2 months'),
            'display_frequency' => $this->faker->randomElement(['always', 'once_per_session', 'once_per_day']),
            'priority' => $this->faker->numberBetween(0, 100),
        ];

        try {
            $this->popupService->validatePopupData($data);
            $this->fail('Expected ValidationException was not thrown for empty title and content');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('title');
            expect($e->errors())->toHaveKey('content');
        }
    }
});

/**
 * Property 1.6: Null dates should pass validation (dates are optional)
 * *For any* popup data with null start_date and end_date, validation SHALL pass
 */
test('property: null dates always pass validation', function () {
    // Run iterations with random data
    for ($i = 0; $i < 50; $i++) {
        $data = [
            'title' => $this->faker->sentence(),
            'content' => $this->faker->paragraph(),
            'start_date' => null,
            'end_date' => null,
            'display_frequency' => $this->faker->randomElement(['always', 'once_per_session', 'once_per_day']),
            'priority' => $this->faker->numberBetween(0, 100),
        ];

        // Should not throw any exception
        $result = $this->popupService->validatePopupData($data);
        expect($result)->toBe($data);
    }
});

/**
 * Property 1.7: Invalid display frequency should cause validation failure
 * *For any* popup data with invalid display_frequency, validation SHALL fail
 */
test('property: invalid display frequency causes validation failure', function () {
    // Run iterations with random invalid frequencies
    for ($i = 0; $i < 50; $i++) {
        $invalidFrequency = $this->faker->word() . '_invalid_' . $this->faker->randomNumber();
        
        $data = [
            'title' => $this->faker->sentence(),
            'content' => $this->faker->paragraph(),
            'display_frequency' => $invalidFrequency,
            'priority' => $this->faker->numberBetween(0, 100),
        ];

        try {
            $this->popupService->validatePopupData($data);
            $this->fail('Expected ValidationException was not thrown for invalid display_frequency');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('display_frequency');
        }
    }
});
