<?php

use App\Models\User;
use App\Models\Driver\DriverOnboardingApplication;
use App\Models\Vehicle\VehicleGrade;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Vehicle\VehicleMake;
use App\Models\Vehicle\VehicleModel;
use App\Models\Vehicle\Vehicle;
use App\Http\Controllers\Api\Driver\Mobile\OnboardingController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('verifies mobile otp prefills an existing user and protects the draft with an onboarding token', function (): void {
    $user = User::create([
        'first_name' => 'Nimal', 'last_name' => 'Perera', 'email' => 'nimal@example.com',
        'phone' => '+94771234567', 'password' => bcrypt('Password1!'), 'is_active' => true,
    ]);
    Cache::put('driver_auth_otp:'.sha1('+94771234567'), Hash::make('123456'), now()->addMinutes(10));

    $verified = $this->postJson('/api/driver/auth/verify-otp', ['mobile' => '+94771234567', 'otp' => '123456'])
        ->assertCreated()->assertJsonPath('data.application.user_id', $user->id)
        ->assertJsonPath('data.application.payload.identity.first_name', 'Nimal');

    $token = $verified->json('data.onboarding_token');
    $this->getJson('/api/driver/onboarding')->assertUnauthorized();
    $this->withToken($token)->patchJson('/api/driver/onboarding/steps/1', [
        'first_name' => 'Nimal', 'last_name' => 'Perera', 'email' => 'nimal@example.com', 'nic' => '901234567V',
    ])->assertOk()->assertJsonPath('data.current_step', 2);

    DriverOnboardingApplication::first()->update([
        'status' => 'changes_requested',
        'review_issues' => [['field' => 'identity.nic', 'message' => 'NIC is unclear.']],
    ]);
    $this->withToken($token)->patchJson('/api/driver/onboarding/steps/1', [
        'first_name' => 'Changed', 'last_name' => 'Perera', 'email' => 'nimal@example.com', 'nic' => '200012345678',
    ])->assertForbidden();
    $this->withToken($token)->patchJson('/api/driver/onboarding/steps/1', [
        'first_name' => 'Nimal', 'last_name' => 'Perera', 'email' => 'nimal@example.com', 'nic' => '200012345678',
    ])->assertOk();
});

it('classifies an approved vehicle through a make model and latest grade vehicle group', function (): void {
    $reviewer = User::create(['first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin@example.com',
        'phone' => '+94770000001', 'password' => bcrypt('Password1!'), 'is_active' => true]);
    $driverUser = User::create(['first_name' => 'Nimal', 'last_name' => 'Perera', 'email' => 'driver@example.com',
        'phone' => '+94770000002', 'password' => bcrypt('Password1!'), 'is_active' => true]);
    $make = VehicleMake::create(['name' => 'Toyota']);
    $model = VehicleModel::create(['make_id' => $make->id, 'name' => 'Axio']);
    VehicleGrade::create(['name' => 'Economy']);
    $latestGrade = VehicleGrade::create(['name' => 'Standard']);
    $latestGrade->forceFill(['created_at' => now()->addSecond()])->saveQuietly();
    $application = DriverOnboardingApplication::create([
        'user_id' => $driverUser->id, 'mobile' => $driverUser->phone, 'access_token_hash' => hash('sha256', 'token'),
        'mobile_verified_at' => now(), 'status' => 'submitted', 'payload' => [
            'identity' => ['first_name' => 'Nimal', 'last_name' => 'Perera', 'email' => 'driver@example.com', 'nic' => '901234567V', 'dob' => '1990-05-02'],
            'address' => ['address' => 'Colombo', 'country_id' => null, 'state_id' => null, 'city' => 'Colombo', 'postal_code' => '00100'],
            'vehicle' => ['make_id' => $make->id, 'model_id' => $model->id, 'model_year' => 2024, 'color' => 'White',
                'registration_year' => 2024, 'license_plate' => 'CAB-1234', 'is_owner' => true],
        ],
    ]);

    $method = new ReflectionMethod(OnboardingController::class, 'approve');
    $method->invoke(app(OnboardingController::class), $application, $reviewer->id);

    $application->refresh();
    $vehicle = Vehicle::withoutGlobalScopes()->findOrFail($application->vehicle_id);
    $group = VehicleGroup::findOrFail($vehicle->vehicle_group_id);
    expect($group->make_id)->toBe($make->id)
        ->and($group->model_id)->toBe($model->id)
        ->and($group->grade_id)->toBe($latestGrade->id)
        ->and($vehicle->getAttributes())->not->toHaveKeys(['make_id', 'model_id']);
});
