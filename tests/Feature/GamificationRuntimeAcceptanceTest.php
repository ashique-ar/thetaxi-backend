<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use QCod\Gamify\Badge;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function createGamificationUser(string $email, int $points = 0): User
{
    $user = User::create([
        'first_name' => 'Runtime',
        'last_name' => 'Viewer',
        'email' => $email,
    ]);
    $user->forceFill(['reputation' => $points])->save();

    return $user;
}

it('returns representative badge and all-time leaderboard envelopes to a gamification viewer', function (): void {
    $viewer = createGamificationUser('gamification-viewer@example.test');
    $leader = createGamificationUser('gamification-leader@example.test', 75);
    $viewer->givePermissionTo(Permission::findOrCreate('gamification.view', 'api'));

    Badge::create([
        'name' => 'First Trip',
        'description' => 'Completed a first trip',
        'icon' => 'military_tech',
        'level' => 1,
    ]);

    actingAs($viewer, 'api')
        ->getJson('/api/badges')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.badges.0.name', 'First Trip')
        ->assertJsonPath('data.badges.0.is_active', true)
        ->assertJsonPath('data.badges.0.earned_count', 0);

    actingAs($viewer, 'api')
        ->getJson('/api/leaderboard?limit=5&period=week')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.period', 'all')
        ->assertJsonPath('data.limit', 5)
        ->assertJsonFragment([
            'id' => $leader->id,
            'total_points' => 75,
            'rank' => 1,
        ]);

    actingAs($viewer, 'api')
        ->getJson('/api/leaderboard?limit=500')
        ->assertUnprocessable()
        ->assertJsonPath('status', 'error');
});

it('keeps statistics restricted while allowing the core gamification read experience', function (): void {
    $viewer = createGamificationUser('restricted-gamification-viewer@example.test');
    $viewer->givePermissionTo(Permission::findOrCreate('gamification.view', 'api'));

    actingAs($viewer, 'api')->getJson('/api/leaderboard')->assertOk();
    actingAs($viewer, 'api')->getJson('/api/badges')->assertOk();
    actingAs($viewer, 'api')->getJson('/api/badges/stats')->assertForbidden();

    $viewer->givePermissionTo(Permission::findOrCreate('gamification.stats', 'api'));

    actingAs($viewer, 'api')
        ->getJson('/api/badges/stats')
        ->assertOk()
        ->assertJson([
            'status' => 'success',
            'data' => [
                'total_badges' => 0,
                'active_badges' => 0,
                'inactive_badges' => 0,
                'levels' => [],
            ],
        ]);
});

it('allows loyalty read roles without exposing management mutations', function (): void {
    $viewer = createGamificationUser('loyalty-read-viewer@example.test');
    $customerUser = createGamificationUser('loyalty-member@example.test', 20);
    $customer = Customer::create(['user_id' => $customerUser->id]);
    $viewer->givePermissionTo(Permission::findOrCreate('loyalty.view', 'api'));

    actingAs($viewer, 'api')
        ->getJson('/api/customers/loyalty/stats')
        ->assertOk()
        ->assertJsonPath('status', 'success');

    actingAs($viewer, 'api')
        ->postJson("/api/customers/{$customer->id}/loyalty/points", [
            'points' => 10,
            'reason' => 'Must remain restricted',
        ])
        ->assertForbidden();
});
