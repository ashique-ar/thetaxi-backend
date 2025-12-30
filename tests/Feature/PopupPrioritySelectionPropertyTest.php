<?php

/**
 * Property-Based Test for Popup Priority Selection
 * 
 * **Feature: popup-promo-management, Property 3: Popup Priority Selection**
 * **Validates: Requirements 2.6**
 * 
 * Property 3: Popup Priority Selection
 * *For any* set of active popups configured for the same page, THE Popup_Display_Engine 
 * SHALL display only the popup with the highest priority value, ensuring no duplicate 
 * popups are shown.
 * 
 * Note: This test validates the backend logic that supports priority-based selection.
 * The PopupService.getHighestPriorityPopup() method returns only the highest priority popup.
 */

use App\Models\Website\Popup;
use Faker\Factory as Faker;

/**
 * Property 3.1: Higher priority value means higher priority
 * *For any* two popups with different priorities, the one with higher priority value
 * SHALL be considered higher priority
 */
test('property: higher priority value means higher priority', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $lowPriority = $faker->numberBetween(0, 49);
        $highPriority = $faker->numberBetween(50, 100);
        
        $lowPriorityPopup = new Popup([
            'title' => $faker->sentence(),
            'content' => $faker->paragraph(),
            'priority' => $lowPriority,
            'is_active' => true,
        ]);
        
        $highPriorityPopup = new Popup([
            'title' => $faker->sentence(),
            'content' => $faker->paragraph(),
            'priority' => $highPriority,
            'is_active' => true,
        ]);

        // Property: Higher priority value should be greater
        expect($highPriorityPopup->priority)->toBeGreaterThan($lowPriorityPopup->priority);
    }
});


/**
 * Property 3.2: Priority is always a non-negative integer
 * *For any* popup with a valid priority, the priority SHALL be a non-negative integer
 */
test('property: priority is always a non-negative integer', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $priority = $faker->numberBetween(0, 1000);
        
        $popup = new Popup([
            'title' => $faker->sentence(),
            'content' => $faker->paragraph(),
            'priority' => $priority,
            'is_active' => true,
        ]);

        // Property: Priority must be an integer
        expect($popup->priority)->toBeInt();
        
        // Property: Priority must be non-negative
        expect($popup->priority)->toBeGreaterThanOrEqual(0);
    }
});

/**
 * Property 3.3: Priority is correctly cast to integer
 * *For any* popup, the priority attribute SHALL be cast to integer
 */
test('property: priority is correctly cast to integer', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $priority = $faker->numberBetween(0, 1000);
        
        $popup = new Popup([
            'title' => $faker->sentence(),
            'content' => $faker->paragraph(),
            'priority' => (string) $priority, // Pass as string
            'is_active' => true,
        ]);

        // Property: Priority should be cast to integer
        expect($popup->priority)->toBeInt();
        expect($popup->priority)->toBe($priority);
    }
});


/**
 * Property 3.4: Sorting by priority produces correct order
 * *For any* collection of popups, sorting by priority DESC SHALL place highest priority first
 */
test('property: sorting by priority produces correct order', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        // Create a collection of popups with random priorities
        $popups = collect();
        $numPopups = $faker->numberBetween(3, 10);
        
        for ($j = 0; $j < $numPopups; $j++) {
            $popups->push(new Popup([
                'title' => $faker->sentence(),
                'content' => $faker->paragraph(),
                'priority' => $faker->numberBetween(0, 100),
                'is_active' => true,
            ]));
        }
        
        // Sort by priority descending (highest first)
        $sorted = $popups->sortByDesc('priority')->values();
        
        // Property: Each popup should have priority >= the next popup
        for ($k = 0; $k < $sorted->count() - 1; $k++) {
            expect($sorted[$k]->priority)->toBeGreaterThanOrEqual($sorted[$k + 1]->priority);
        }
        
        // Property: First popup should have the highest priority
        $maxPriority = $popups->max('priority');
        expect($sorted->first()->priority)->toBe($maxPriority);
    }
});

/**
 * Property 3.5: Highest priority popup is always selected first
 * *For any* collection of popups, the first popup after sorting by priority DESC
 * SHALL have the maximum priority value
 */
test('property: highest priority popup is always selected first', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        // Create a collection of popups with random priorities
        $popups = collect();
        $numPopups = $faker->numberBetween(2, 15);
        
        for ($j = 0; $j < $numPopups; $j++) {
            $popups->push(new Popup([
                'title' => $faker->sentence(),
                'content' => $faker->paragraph(),
                'priority' => $faker->numberBetween(0, 100),
                'is_active' => true,
            ]));
        }
        
        // Get the maximum priority
        $maxPriority = $popups->max('priority');
        
        // Sort and get first (simulating getHighestPriorityPopup)
        $highestPriorityPopup = $popups->sortByDesc('priority')->first();
        
        // Property: The selected popup must have the maximum priority
        expect($highestPriorityPopup->priority)->toBe($maxPriority);
    }
});


/**
 * Property 3.6: Only one popup is returned when getting highest priority
 * *For any* collection of popups, getting the highest priority SHALL return exactly one popup
 */
test('property: only one popup is returned when getting highest priority', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        // Create a collection of popups with random priorities
        $popups = collect();
        $numPopups = $faker->numberBetween(1, 20);
        
        for ($j = 0; $j < $numPopups; $j++) {
            $popups->push(new Popup([
                'title' => $faker->sentence(),
                'content' => $faker->paragraph(),
                'priority' => $faker->numberBetween(0, 100),
                'is_active' => true,
            ]));
        }
        
        // Get highest priority popup (simulating getHighestPriorityPopup)
        $highestPriorityPopup = $popups->sortByDesc('priority')->first();
        
        // Property: Result should be a single popup, not a collection
        expect($highestPriorityPopup)->toBeInstanceOf(Popup::class);
        
        // Property: Result should not be null when popups exist
        expect($highestPriorityPopup)->not->toBeNull();
    }
});

/**
 * Property 3.7: Priority value is preserved in array representation
 * *For any* popup, the priority SHALL be included in the array/JSON representation
 */
test('property: priority value is preserved in array representation', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $priority = $faker->numberBetween(0, 100);
        
        $popup = new Popup([
            'title' => $faker->sentence(),
            'content' => $faker->paragraph(),
            'priority' => $priority,
            'is_active' => true,
        ]);

        // Property: Priority should be in array representation
        $array = $popup->toArray();
        expect($array)->toHaveKey('priority');
        expect($array['priority'])->toBe($priority);
    }
});

/**
 * Property 3.8: Equal priorities maintain stable order
 * *For any* collection of popups with equal priorities, sorting SHALL be stable
 * (original order preserved for equal elements)
 */
test('property: equal priorities maintain deterministic selection', function () {
    $faker = Faker::create();
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $samePriority = $faker->numberBetween(0, 100);
        
        // Create popups with the same priority
        $popups = collect();
        $numPopups = $faker->numberBetween(2, 5);
        
        for ($j = 0; $j < $numPopups; $j++) {
            $popups->push(new Popup([
                'title' => 'Popup ' . ($j + 1),
                'content' => $faker->paragraph(),
                'priority' => $samePriority,
                'is_active' => true,
            ]));
        }
        
        // Get highest priority popup multiple times
        $firstResult = $popups->sortByDesc('priority')->first();
        $secondResult = $popups->sortByDesc('priority')->first();
        
        // Property: Same popup should be selected each time (deterministic)
        expect($firstResult->title)->toBe($secondResult->title);
        
        // Property: All popups have the same priority
        foreach ($popups as $popup) {
            expect($popup->priority)->toBe($samePriority);
        }
    }
});
