<?php

use App\Http\Controllers\Api\LoyaltyController;
use App\Models\Customer;
use App\Models\LoyaltyTier;
use App\Models\LoyaltyPointTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('persists manual additions and penalties in the UUID-native loyalty ledger', function (): void {
    $actor = User::create([
        'first_name' => 'Operations',
        'last_name' => 'Manager',
        'email' => 'loyalty-actor@example.test',
    ]);
    $customerUser = User::create([
        'first_name' => 'Loyalty',
        'last_name' => 'Customer',
        'email' => 'loyalty-customer@example.test',
    ]);
    $customer = Customer::create(['user_id' => $customerUser->id]);
    $controller = app(LoyaltyController::class);

    $addition = Request::create('/api/customers/' . $customer->id . '/loyalty/points', 'POST', [
        'points' => 25,
        'reason' => 'Service recovery bonus',
    ]);
    $addition->setUserResolver(fn () => $actor);

    $additionResponse = $controller->addPoints($addition, $customer);

    expect($additionResponse->getStatusCode())->toBe(200)
        ->and($customerUser->fresh()->getPoints())->toBe(25);
    $this->assertDatabaseHas('loyalty_point_transactions', [
        'customer_id' => $customer->id,
        'type' => 'adjusted',
        'points' => 25,
        'balance_after' => 25,
    ]);

    $penalty = Request::create('/api/customers/' . $customer->id . '/loyalty/adjustments', 'POST', [
        'points' => 5,
        'adjustment_type' => 'penalty',
        'reason' => 'Duplicate points correction',
    ]);
    $penalty->setUserResolver(fn () => $actor);

    $penaltyResponse = $controller->adjustPoints($penalty, $customer);

    expect($penaltyResponse->getStatusCode())->toBe(200)
        ->and($customerUser->fresh()->getPoints())->toBe(20);
    $this->assertDatabaseHas('loyalty_point_transactions', [
        'customer_id' => $customer->id,
        'type' => 'adjusted',
        'points' => -5,
        'balance_after' => 20,
    ]);

    $meta = LoyaltyPointTransaction::query()->where('points', -5)->firstOrFail()->metadata;
    expect($meta)->toMatchArray([
        'reason' => 'Duplicate points correction',
        'adjustment_type' => 'penalty',
        'processed_by' => $actor->id,
    ]);

    $activity = json_decode(
        $controller->getLoyaltyActivity(Request::create('/api/customers/loyalty/activity'))->getContent(),
        true,
    );
    $penaltyActivity = collect($activity['data']['activity'])->firstWhere('points', -5);
    expect($activity['data']['activity'])->toHaveCount(2)
        ->and($penaltyActivity)->toMatchArray([
            'customer_id' => $customer->id,
            'customer_name' => 'Loyalty Customer',
            'customer_email' => 'loyalty-customer@example.test',
            'action_type' => 'adjusted',
            'points' => -5,
            'current_points' => 20,
            'description' => 'Duplicate points correction',
        ]);

    LoyaltyTier::create([
        'name' => 'bronze',
        'display_name' => 'Bronze',
        'min_points' => 0,
        'points_earning_multiplier' => 1.25,
        'privileges' => ['Member rates'],
        'priority_support' => true,
        'is_active' => true,
    ]);

    $tiers = json_decode($controller->getLoyaltyTiers()->getContent(), true);
    expect($tiers['data']['tiers'])->toHaveCount(1)
        ->and($tiers['data']['tiers'][0])->toMatchArray([
            'name' => 'Bronze',
            'min_points' => 0,
            'benefits' => ['Member rates', 'Priority support'],
            'customers_count' => 0,
            'multiplier' => 1.25,
        ]);

    $stats = json_decode($controller->getLoyaltyStats()->getContent(), true);
    expect($stats['data'])->toMatchArray([
        'total_members' => 1,
        'members_with_points' => 1,
        'outstanding_points' => 20,
        'points_issued' => 25,
        'points_deducted' => 5,
        'average_balance' => 20,
    ]);

    $leaderboard = json_decode(
        $controller->getLoyaltyLeaderboard(Request::create('/api/customers/loyalty/leaderboard'))->getContent(),
        true,
    );
    expect($leaderboard['data']['leaderboard'])->toHaveCount(1)
        ->and($leaderboard['data']['leaderboard'][0])->toMatchArray([
            'id' => $customer->id,
            'name' => 'Loyalty Customer',
            'email' => 'loyalty-customer@example.test',
            'points' => 20,
            'rank' => 1,
        ]);
});
