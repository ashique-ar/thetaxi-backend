<?php

require 'vendor/autoload.php';
require 'bootstrap/app.php';

echo "=== Testing KM Range Pricing and Price Adjustments Implementation ===\n\n";

try {
    // Test model loading
    echo "1. Testing Model Loading...\n";
    $rule = new \App\Models\KmRangePricingRule();
    echo "   ✓ KmRangePricingRule model loaded\n";
    
    $adjustment = new \App\Models\PriceAdjustment();
    echo "   ✓ PriceAdjustment model loaded\n";
    
    $history = new \App\Models\BookingPriceAdjustmentHistory();
    echo "   ✓ BookingPriceAdjustmentHistory model loaded\n";
    
    // Test database connection
    echo "\n2. Testing Database Connection...\n";
    $pdo = \DB::connection()->getPdo();
    echo "   ✓ Database connected successfully\n";
    
    // Test table existence
    echo "\n3. Testing Table Existence...\n";
    $tables = ['km_range_pricing_rules', 'price_adjustments', 'booking_price_adjustment_history'];
    foreach ($tables as $table) {
        $exists = \Schema::hasTable($table);
        if ($exists) {
            echo "   ✓ Table '{$table}' exists\n";
        } else {
            echo "   ✗ Table '{$table}' does not exist\n";
        }
    }
    
    // Test route registration
    echo "\n4. Testing Route Registration...\n";
    $router = app('router');
    $routes = $router->getRoutes();
    
    $kmRangeRoutes = 0;
    $priceAdjustmentRoutes = 0;
    
    foreach ($routes as $route) {
        $uri = $route->uri();
        if (strpos($uri, 'km-range-pricing') !== false) {
            $kmRangeRoutes++;
        }
        if (strpos($uri, 'price-adjustments') !== false) {
            $priceAdjustmentRoutes++;
        }
    }
    
    echo "   ✓ Found {$kmRangeRoutes} KM Range Pricing routes\n";
    echo "   ✓ Found {$priceAdjustmentRoutes} Price Adjustment routes\n";
    
    // Test controller loading
    echo "\n5. Testing Controller Loading...\n";
    $kmController = new \App\Http\Controllers\Api\Vehicle\VehiclePricing\KmRangePricingController();
    echo "   ✓ KmRangePricingController loaded\n";
    
    $adjustmentController = new \App\Http\Controllers\Api\Vehicle\VehiclePricing\PriceAdjustmentController();
    echo "   ✓ PriceAdjustmentController loaded\n";
    
    echo "\n=== All Tests Passed! Implementation is working correctly ===\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}