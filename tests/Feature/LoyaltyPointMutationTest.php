<?php

use App\Http\Controllers\Api\LoyaltyController;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('persists manual additions and penalties with an attributed reputation audit', function (): void {
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
    $this->assertDatabaseHas('reputations', [
        'payee_id' => $customerUser->id,
        'name' => 'manual_bonus',
        'point' => 25,
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
    $this->assertDatabaseHas('reputations', [
        'payee_id' => $customerUser->id,
        'name' => 'manual_penalty',
        'point' => -5,
    ]);

    $meta = json_decode((string) $customerUser->reputations()->where('name', 'manual_penalty')->value('meta'), true);
    expect($meta)->toMatchArray([
        'reason' => 'Duplicate points correction',
        'adjustment_type' => 'penalty',
        'processed_by' => $actor->id,
    ]);
});
