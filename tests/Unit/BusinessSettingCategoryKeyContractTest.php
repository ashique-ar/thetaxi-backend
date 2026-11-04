<?php

use App\Http\Controllers\Api\BusinessSettingController;

it('accepts the Tour itinerary toggle in application booking settings', function () {
    $categories = (new ReflectionClass(BusinessSettingController::class))
        ->getReflectionConstant('CATEGORY_KEYS')
        ->getValue();

    expect($categories['booking'])->toContain('tour_ai_itinerary_enabled');
});
