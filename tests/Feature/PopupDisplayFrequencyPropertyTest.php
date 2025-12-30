<?php

/**
 * Property-Based Test for Popup Display Frequency Enforcement
 * 
 * **Feature: popup-promo-management, Property 2: Popup Display Frequency Enforcement**
 * **Validates: Requirements 2.2, 2.3**
 * 
 * Property 2: Popup Display Frequency Enforcement
 * *For any* popup with display frequency set to "once_per_session" or "once_per_day", 
 * THE Popup_Display_Engine SHALL show the popup at most once per the configured time period, 
 * regardless of how many times the page is loaded.
 * 
 * Note: This test validates the backend model logic that supports display frequency.
 * The actual client-side enforcement is handled by JavaScript using localStorage/sessionStorage.
 * This test ensures the popup model correctly handles frequency values and provides them
 * for client-side enforcement.
 */

use App\Models\Website\Popup;
use Faker\Factory as Faker;

/**
 * Property 2.1: Popup display frequency is always one of the valid values
 * *For any* popup created with a valid frequency, the stored frequency SHALL be one of:
 * 'always', 'once_per_session', or 'once_per_day'
 */
test('property: popup display frequency is always a valid value', function () {
    $faker = Faker::create();
    $validFrequencies = [
        Popup::FREQUENCY_ALWAYS,
        Popup::FREQUENCY_ONCE_PER_SESSION,
        Popup::FREQUENCY_ONCE_PER_DAY,
    ];

    // Run 100 iterations with random data
    for ($i = 0; $i < 100; $i++) {
        $frequency = $faker->randomElement($validFrequencies);
        
        $popup = new Popup([
            'title' => $faker->sentence(),
            'content' => $faker->paragraph(),
            'display_frequency' => $frequency,
            'target_pages' => ['all'],
            'priority' => $faker->numberBetween(0, 100),
            'is_active' => true,
        ]);

        // Property: The stored frequency must be one of the valid values
        expect($popup->display_frequency)->toBeIn($validFrequencies);
        expect($popup->display_frequency)->toBe($frequency);
    }
});


/**
 * Property 2.2: Once per session frequency is correctly stored
 * *For any* popup with 'once_per_session' frequency, the model SHALL store
 * the correct display_frequency value for client-side enforcement
 */
test('property: once per session frequency is correctly stored', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $popup = new Popup([
            'title' => $faker->sentence(),
            'content' => $faker->paragraph(),
            'display_frequency' => Popup::FREQUENCY_ONCE_PER_SESSION,
            'target_pages' => ['all'],
            'priority' => $faker->numberBetween(0, 100),
            'is_active' => true,
        ]);

        // Property: The frequency must be exactly 'once_per_session'
        expect($popup->display_frequency)->toBe('once_per_session');
        
        // Property: The popup array representation must include display_frequency
        $popupArray = $popup->toArray();
        expect($popupArray)->toHaveKey('display_frequency');
        expect($popupArray['display_frequency'])->toBe('once_per_session');
    }
});

/**
 * Property 2.3: Once per day frequency is correctly stored
 * *For any* popup with 'once_per_day' frequency, the model SHALL store
 * the correct display_frequency value for client-side enforcement
 */
test('property: once per day frequency is correctly stored', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $popup = new Popup([
            'title' => $faker->sentence(),
            'content' => $faker->paragraph(),
            'display_frequency' => Popup::FREQUENCY_ONCE_PER_DAY,
            'target_pages' => ['all'],
            'priority' => $faker->numberBetween(0, 100),
            'is_active' => true,
        ]);

        // Property: The frequency must be exactly 'once_per_day'
        expect($popup->display_frequency)->toBe('once_per_day');
        
        // Property: The popup array representation must include display_frequency
        $popupArray = $popup->toArray();
        expect($popupArray)->toHaveKey('display_frequency');
        expect($popupArray['display_frequency'])->toBe('once_per_day');
    }
});


/**
 * Property 2.4: Always frequency is correctly stored
 * *For any* popup with 'always' frequency, the model SHALL store
 * the correct display_frequency value
 */
test('property: always frequency is correctly stored', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $popup = new Popup([
            'title' => $faker->sentence(),
            'content' => $faker->paragraph(),
            'display_frequency' => Popup::FREQUENCY_ALWAYS,
            'target_pages' => ['all'],
            'priority' => $faker->numberBetween(0, 100),
            'is_active' => true,
        ]);

        // Property: The frequency must be exactly 'always'
        expect($popup->display_frequency)->toBe('always');
        
        // Property: The popup array representation must include display_frequency
        $popupArray = $popup->toArray();
        expect($popupArray)->toHaveKey('display_frequency');
        expect($popupArray['display_frequency'])->toBe('always');
    }
});

/**
 * Property 2.5: Frequency value is preserved in model attributes
 * *For any* popup with a specific frequency, the frequency SHALL be accessible
 * via both attribute access and array conversion
 */
test('property: frequency value is preserved in model attributes', function () {
    $faker = Faker::create();
    $validFrequencies = [
        Popup::FREQUENCY_ALWAYS,
        Popup::FREQUENCY_ONCE_PER_SESSION,
        Popup::FREQUENCY_ONCE_PER_DAY,
    ];

    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $frequency = $faker->randomElement($validFrequencies);
        
        $popup = new Popup([
            'title' => $faker->sentence(),
            'content' => $faker->paragraph(),
            'display_frequency' => $frequency,
            'target_pages' => $faker->randomElements(['all', 'homepage', 'checkout'], $faker->numberBetween(1, 3)),
            'priority' => $faker->numberBetween(0, 100),
            'is_active' => $faker->boolean(),
        ]);

        // Property: Frequency accessible via attribute
        expect($popup->display_frequency)->toBe($frequency);
        
        // Property: Frequency accessible via getAttribute
        expect($popup->getAttribute('display_frequency'))->toBe($frequency);
        
        // Property: Frequency in array representation
        $array = $popup->toArray();
        expect($array['display_frequency'])->toBe($frequency);
    }
});


/**
 * Property 2.6: Display frequency options are complete
 * *For any* call to getDisplayFrequencyOptions, the result SHALL contain all valid frequencies
 */
test('property: display frequency options are complete', function () {
    $faker = Faker::create();
    
    // Run 100 iterations to ensure consistency
    for ($i = 0; $i < 100; $i++) {
        $options = Popup::getDisplayFrequencyOptions();
        
        // Property: Options must contain all three frequencies
        expect($options)->toHaveKey(Popup::FREQUENCY_ALWAYS);
        expect($options)->toHaveKey(Popup::FREQUENCY_ONCE_PER_SESSION);
        expect($options)->toHaveKey(Popup::FREQUENCY_ONCE_PER_DAY);
        
        // Property: Options must have exactly 3 entries
        expect(count($options))->toBe(3);
        
        // Property: Each option must have a non-empty label
        foreach ($options as $key => $label) {
            expect($label)->not->toBeEmpty();
            expect($label)->toBeString();
        }
    }
});

/**
 * Property 2.7: Frequency constants are correctly defined
 * *For any* frequency constant, the value SHALL match the expected string
 */
test('property: frequency constants are correctly defined', function () {
    // Run 100 iterations to ensure consistency
    for ($i = 0; $i < 100; $i++) {
        // Property: Constants must have correct values
        expect(Popup::FREQUENCY_ALWAYS)->toBe('always');
        expect(Popup::FREQUENCY_ONCE_PER_SESSION)->toBe('once_per_session');
        expect(Popup::FREQUENCY_ONCE_PER_DAY)->toBe('once_per_day');
    }
});
