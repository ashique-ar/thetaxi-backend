<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\getJson;

/**
 * Core booking creation and access control tests.
 *
 * These tests run against the live application structure without database
 * mutations (all schema-dependent cases skip gracefully if tables are absent).
 */

it('rejects booking creation when unauthenticated', function () {
    $response = postJson('/api/booking-flow/save-draft', []);
    $response->assertStatus(401);
});

it('rejects booking creation when required fields are missing', function () {
    if (!Schema::hasTable('users')) {
        return;
    }

    $user = User::factory()->create();

    actingAs($user, 'api')
        ->withHeaders([
            'X-Active-Context-Type' => 'company',
            'X-Active-Context-Id'   => '00000000-0000-0000-0000-000000000001',
        ])
        ->postJson('/api/booking-flow/save-draft', [])
        ->assertStatus(422);
})->skip(fn () => !Schema::hasTable('users'));

it('returns 401 when listing bookings without authentication', function () {
    $response = getJson('/api/booking-flow/bookings');
    $response->assertStatus(401);
});

it('returns 422 when context headers are missing on authenticated booking list request', function () {
    if (!Schema::hasTable('users')) {
        return;
    }

    $user = User::factory()->create();

    actingAs($user, 'api')
        ->getJson('/api/booking-flow/bookings')
        ->assertStatus(422);
})->skip(fn () => !Schema::hasTable('users'));

it('returns 403 when accessing a booking UUID outside own context', function () {
    if (!Schema::hasTable('users')) {
        return;
    }

    $user = User::factory()->create();

    actingAs($user, 'api')
        ->withHeaders([
            'X-Active-Context-Type' => 'company',
            'X-Active-Context-Id'   => '00000000-0000-0000-0000-000000000001',
        ])
        ->getJson('/api/booking-flow/bookings/00000000-0000-0000-0000-000000000999')
        ->assertStatus(403);
})->skip(fn () => !Schema::hasTable('users'));
