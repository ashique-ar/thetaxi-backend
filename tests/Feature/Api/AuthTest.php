<?php

use Illuminate\Support\Facades\Hash;
use function Pest\Laravel\post;
use function Pest\Laravel\get;

it('returns 422 when login credentials are missing', function () {
    $response = post('/api/auth/login', []);
    $response->assertStatus(422);
});

it('returns 401 for invalid credentials', function () {
    $response = post('/api/auth/login', [
        'email'    => 'nonexistent@example.com',
        'password' => 'wrongpassword',
    ]);
    $response->assertStatus(401);
});

it('health check returns 200 with expected keys', function () {
    $response = get('/api/health');
    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'timestamp',
            'checks' => ['database', 'cache', 'storage', 'queue'],
        ]);
});

it('protected routes require authentication', function () {
    $response = get('/api/booking-flow/bookings');
    $response->assertStatus(401);
});
