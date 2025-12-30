<?php

/**
 * Property-Based Test for API Authentication Enforcement
 * 
 * **Feature: popup-promo-management, Property 11: API Authentication Enforcement**
 * **Validates: Requirements 7.5**
 * 
 * Property 11: API Authentication Enforcement
 * *For any* request to admin API endpoints (/api/admin/*) without valid authentication, 
 * THE API SHALL return HTTP 401 Unauthorized status.
 */

use Faker\Factory as Faker;

/**
 * Property 11.1: Unauthenticated popup admin requests return 401
 * *For any* request to popup admin endpoints without authentication, SHALL return 401
 */
test('property: unauthenticated popup admin requests return 401', function () {
    $faker = Faker::create();
    
    // Define all popup admin endpoints that require authentication
    $endpoints = [
        ['method' => 'GET', 'uri' => '/api/admin/popups'],
        ['method' => 'GET', 'uri' => '/api/admin/popups/statistics'],
        ['method' => 'POST', 'uri' => '/api/admin/popups'],
        ['method' => 'GET', 'uri' => '/api/admin/popups/' . $faker->uuid()],
        ['method' => 'PUT', 'uri' => '/api/admin/popups/' . $faker->uuid()],
        ['method' => 'DELETE', 'uri' => '/api/admin/popups/' . $faker->uuid()],
        ['method' => 'PATCH', 'uri' => '/api/admin/popups/' . $faker->uuid() . '/toggle'],
    ];
    
    // Run 100 iterations testing random endpoints
    for ($i = 0; $i < 100; $i++) {
        $endpoint = $faker->randomElement($endpoints);
        
        // Make request without authentication
        $response = match ($endpoint['method']) {
            'GET' => $this->getJson($endpoint['uri']),
            'POST' => $this->postJson($endpoint['uri'], [
                'title' => $faker->sentence(),
                'content' => $faker->paragraph(),
            ]),
            'PUT' => $this->putJson($endpoint['uri'], [
                'title' => $faker->sentence(),
            ]),
            'DELETE' => $this->deleteJson($endpoint['uri']),
            'PATCH' => $this->patchJson($endpoint['uri']),
        };
        
        // Verify 401 Unauthorized response
        expect($response->status())->toBe(401);
    }
});

/**
 * Property 11.2: Unauthenticated promo code admin requests return 401
 * *For any* request to promo code admin endpoints without authentication, SHALL return 401
 */
test('property: unauthenticated promo code admin requests return 401', function () {
    $faker = Faker::create();
    
    // Define all promo code admin endpoints that require authentication
    $endpoints = [
        ['method' => 'GET', 'uri' => '/api/admin/promo-codes'],
        ['method' => 'GET', 'uri' => '/api/admin/promo-codes/statistics'],
        ['method' => 'POST', 'uri' => '/api/admin/promo-codes'],
        ['method' => 'GET', 'uri' => '/api/admin/promo-codes/' . $faker->uuid()],
        ['method' => 'PUT', 'uri' => '/api/admin/promo-codes/' . $faker->uuid()],
        ['method' => 'DELETE', 'uri' => '/api/admin/promo-codes/' . $faker->uuid()],
        ['method' => 'PATCH', 'uri' => '/api/admin/promo-codes/' . $faker->uuid() . '/toggle'],
        ['method' => 'GET', 'uri' => '/api/admin/promo-codes/' . $faker->uuid() . '/analytics'],
    ];
    
    // Run 100 iterations testing random endpoints
    for ($i = 0; $i < 100; $i++) {
        $endpoint = $faker->randomElement($endpoints);
        
        // Make request without authentication
        $response = match ($endpoint['method']) {
            'GET' => $this->getJson($endpoint['uri']),
            'POST' => $this->postJson($endpoint['uri'], [
                'code' => strtoupper($faker->regexify('[A-Z0-9]{8}')),
                'name' => $faker->words(3, true),
                'discount_type' => 'percentage',
                'discount_value' => 10,
            ]),
            'PUT' => $this->putJson($endpoint['uri'], [
                'name' => $faker->words(3, true),
            ]),
            'DELETE' => $this->deleteJson($endpoint['uri']),
            'PATCH' => $this->patchJson($endpoint['uri']),
        };
        
        // Verify 401 Unauthorized response
        expect($response->status())->toBe(401);
    }
});

/**
 * Property 11.3: All admin endpoints consistently require authentication
 * *For any* admin endpoint, unauthenticated requests SHALL always return 401
 */
test('property: all admin endpoints consistently require authentication', function () {
    $faker = Faker::create();
    
    // Combine all admin endpoints
    $allEndpoints = [
        // Popup endpoints
        ['method' => 'GET', 'uri' => '/api/admin/popups'],
        ['method' => 'POST', 'uri' => '/api/admin/popups'],
        // Promo code endpoints
        ['method' => 'GET', 'uri' => '/api/admin/promo-codes'],
        ['method' => 'POST', 'uri' => '/api/admin/promo-codes'],
    ];
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        foreach ($allEndpoints as $endpoint) {
            // Make request without authentication
            $response = match ($endpoint['method']) {
                'GET' => $this->getJson($endpoint['uri']),
                'POST' => $this->postJson($endpoint['uri'], []),
            };
            
            // Verify 401 Unauthorized response
            expect($response->status())->toBe(401);
        }
    }
});

/**
 * Property 11.4: Invalid token returns 401
 * *For any* request with invalid/expired token, SHALL return 401
 */
test('property: invalid token returns 401', function () {
    $faker = Faker::create();
    
    $endpoints = [
        ['method' => 'GET', 'uri' => '/api/admin/popups'],
        ['method' => 'GET', 'uri' => '/api/admin/promo-codes'],
    ];
    
    // Run 100 iterations with random invalid tokens
    for ($i = 0; $i < 100; $i++) {
        $invalidToken = $faker->sha256(); // Random invalid token
        $endpoint = $faker->randomElement($endpoints);
        
        // Make request with invalid token
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $invalidToken,
        ])->getJson($endpoint['uri']);
        
        // Verify 401 Unauthorized response
        expect($response->status())->toBe(401);
    }
});

/**
 * Property 11.5: Empty authorization header returns 401
 * *For any* request with empty authorization header, SHALL return 401
 */
test('property: empty authorization header returns 401', function () {
    $faker = Faker::create();
    
    $endpoints = [
        ['method' => 'GET', 'uri' => '/api/admin/popups'],
        ['method' => 'GET', 'uri' => '/api/admin/promo-codes'],
        ['method' => 'POST', 'uri' => '/api/admin/popups'],
        ['method' => 'POST', 'uri' => '/api/admin/promo-codes'],
    ];
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $endpoint = $faker->randomElement($endpoints);
        
        // Make request with empty authorization header
        $response = $this->withHeaders([
            'Authorization' => '',
        ])->json($endpoint['method'], $endpoint['uri'], []);
        
        // Verify 401 Unauthorized response
        expect($response->status())->toBe(401);
    }
});

/**
 * Property 11.6: Malformed authorization header returns 401
 * *For any* request with malformed authorization header, SHALL return 401
 */
test('property: malformed authorization header returns 401', function () {
    $faker = Faker::create();
    
    $malformedHeaders = [
        'Bearer', // Missing token
        'Bearer ', // Empty token
        'Basic ' . base64_encode('user:pass'), // Wrong auth type
        'Token ' . $faker->sha256(), // Wrong prefix
        $faker->sha256(), // No prefix
    ];
    
    $endpoints = [
        '/api/admin/popups',
        '/api/admin/promo-codes',
    ];
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $malformedHeader = $faker->randomElement($malformedHeaders);
        $endpoint = $faker->randomElement($endpoints);
        
        // Make request with malformed authorization header
        $response = $this->withHeaders([
            'Authorization' => $malformedHeader,
        ])->getJson($endpoint);
        
        // Verify 401 Unauthorized response
        expect($response->status())->toBe(401);
    }
});

/**
 * Property 11.7: Public endpoints don't require authentication
 * *For any* public endpoint, requests without authentication SHALL succeed (not 401)
 */
test('property: public endpoints dont require authentication', function () {
    $faker = Faker::create();
    
    // Public endpoints that should NOT require authentication
    $publicEndpoints = [
        '/api/popups/active',
        '/api/popups/highest-priority',
    ];
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $endpoint = $faker->randomElement($publicEndpoints);
        
        // Make request without authentication
        $response = $this->getJson($endpoint);
        
        // Verify NOT 401 (public endpoints should be accessible)
        // They might return 200 or other status, but NOT 401
        expect($response->status())->not->toBe(401);
    }
});

/**
 * Property 11.8: 401 response has correct structure
 * *For any* unauthenticated request, the 401 response SHALL have proper error structure
 */
test('property: 401 response has correct structure', function () {
    $faker = Faker::create();
    
    $endpoints = [
        '/api/admin/popups',
        '/api/admin/promo-codes',
    ];
    
    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $endpoint = $faker->randomElement($endpoints);
        
        // Make request without authentication
        $response = $this->getJson($endpoint);
        
        // Verify 401 status
        expect($response->status())->toBe(401);
        
        // Verify response is JSON
        $response->assertHeader('Content-Type', 'application/json');
    }
});
