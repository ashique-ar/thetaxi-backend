<?php

use Illuminate\Support\Str;

test('unified pricing applies vehicle filters before pagination and caching', function () {
    $source = file_get_contents(__DIR__ . '/../../app/Http/Controllers/Api/Vehicle/VehiclePricing/VehicleGroupPricingController.php');
    $method = Str::between($source, 'public function unifiedPricing(', 'public function getPriceAnalytics(');

    expect($method)->toContain("'vehicle_filters' => \$vehicleFilters")
        ->and($method)->toContain("'grade_id', 'class_id', 'fuel_type_id', 'transmission_id', 'category_id'")
        ->and($method)->toContain("'make_id', 'model_id', 'ownership_type', 'payment_model'")
        ->and($method)->toContain("->whereHas('vehicles'")
        ->and($method)->toContain('$vehicleGroupsPaginated = $vehicleGroupQuery->paginate');
});
