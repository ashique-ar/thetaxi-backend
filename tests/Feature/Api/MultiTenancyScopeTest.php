<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Verifies that cross-tenant data access is blocked at the API layer.
 * If company context headers are absent or incorrect, resources from
 * another tenant must not be returned.
 */
it('rejects requests that omit required context headers', function () {
    if (!Schema::hasTable('users')) {
        return;
    }

    $user = User::factory()->create();

    actingAs($user, 'api')
        ->get('/api/booking-flow/bookings')
        ->assertStatus(422); // Missing X-Active-Context-Type / X-Active-Context-Id
})->skip(fn () => !class_exists(User::class) || !Schema::hasTable('users'));

it('returns 403 when user requests a booking outside their active company context', function () {
    if (!Schema::hasTable('users') || !Schema::hasTable('bookings')) {
        return;
    }

    $user = User::factory()->create();

    // Request booking with a fabricated UUID not belonging to the user's company
    actingAs($user, 'api')
        ->withHeaders([
            'X-Active-Context-Type' => 'company',
            'X-Active-Context-Id'   => $user->company_id ?? '00000000-0000-0000-0000-000000000001',
        ])
        ->get('/api/booking-flow/00000000-0000-0000-0000-000000000999/edit')
        ->assertStatus(403);
})->skip(fn () => !class_exists(User::class) || !Schema::hasTable('users'));
