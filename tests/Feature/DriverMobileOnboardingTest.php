<?php

use App\Models\User;
use App\Models\Country;
use App\Models\State;
use App\Models\Driver\DriverOnboardingApplication;
use App\Models\Driver\Driver;
use App\Models\Vehicle\VehicleMake;
use App\Models\Vehicle\VehicleModel;
use App\Models\Vehicle\Vehicle;
use App\Http\Controllers\Api\Driver\Mobile\OnboardingController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('lists active vehicle makes and models for driver registration', function (): void {
    $make = VehicleMake::create(['name' => 'Toyota']);
    $otherMake = VehicleMake::create(['name' => 'Honda']);
    $model = VehicleModel::create(['make_id' => $make->id, 'name' => 'Axio']);
    $otherModel = VehicleModel::create(['make_id' => $otherMake->id, 'name' => 'Fit']);
    $deletedModel = VehicleModel::create(['make_id' => $make->id, 'name' => 'Old']);
    $deletedModel->delete();

    $this->getJson('/api/driver/onboarding/makes')->assertOk()
        ->assertJsonFragment(['id' => $make->id, 'name' => 'Toyota']);
    $this->getJson("/api/driver/onboarding/makes/{$make->id}/models")->assertOk()
        ->assertJsonFragment(['id' => $model->id, 'make_id' => $make->id, 'name' => 'Axio'])
        ->assertJsonMissing(['id' => $otherModel->id])
        ->assertJsonMissing(['id' => $deletedModel->id]);
    $this->getJson('/api/driver/onboarding/makes/'.Str::uuid().'/models')->assertNotFound();
});

it('saves make and model IDs only when the model belongs to the make', function (): void {
    $make = VehicleMake::create(['name' => 'Toyota']);
    $otherMake = VehicleMake::create(['name' => 'Honda']);
    $model = VehicleModel::create(['make_id' => $make->id, 'name' => 'Axio']);
    $otherModel = VehicleModel::create(['make_id' => $otherMake->id, 'name' => 'Fit']);
    DriverOnboardingApplication::create([
        'mobile' => '+94771234567', 'access_token_hash' => hash('sha256', 'onboarding-token'),
        'mobile_verified_at' => now(), 'status' => 'draft', 'payload' => [],
    ]);
    $payload = ['make_id' => $make->id, 'model_id' => $otherModel->id, 'model_year' => 2024,
        'color' => 'White', 'registration_year' => 2024, 'license_plate' => 'CAB-1234', 'is_owner' => true];

    $this->withToken('onboarding-token')->patchJson('/api/driver/onboarding/steps/4', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('model_id');
    $this->withToken('onboarding-token')->patchJson('/api/driver/onboarding/steps/4',
        [...$payload, 'model_id' => $model->id])->assertOk()
        ->assertJsonPath('data.payload.vehicle.make_id', $make->id)
        ->assertJsonPath('data.payload.vehicle.model_id', $model->id);
});

it('accepts other make and model names for staff classification', function (): void {
    DriverOnboardingApplication::create([
        'mobile' => '+94771234567', 'access_token_hash' => hash('sha256', 'onboarding-token'),
        'mobile_verified_at' => now(), 'status' => 'draft', 'payload' => [],
    ]);
    $vehicle = ['other_make' => 'New Make', 'other_model' => 'New Model', 'model_year' => 2024,
        'color' => 'White', 'registration_year' => 2024, 'license_plate' => 'CAB-1234', 'is_owner' => true];

    $this->withToken('onboarding-token')->patchJson('/api/driver/onboarding/steps/4', $vehicle)->assertOk()
        ->assertJsonPath('data.payload.vehicle.other_make', 'New Make')
        ->assertJsonPath('data.payload.vehicle.other_model', 'New Model')
        ->assertJsonMissingPath('data.payload.vehicle.make_id');
});

it('accepts the mobile other sentinel for make and model IDs', function (): void {
    DriverOnboardingApplication::create([
        'mobile' => '+94771234567', 'access_token_hash' => hash('sha256', 'onboarding-token'),
        'mobile_verified_at' => now(), 'status' => 'draft', 'payload' => [],
    ]);
    $vehicle = ['make_id' => 'other', 'model_id' => 'OTHER', 'model_year' => 2024,
        'color' => 'White', 'registration_year' => 2024, 'license_plate' => 'CAB-1234', 'is_owner' => true];

    $this->withToken('onboarding-token')->patchJson('/api/driver/onboarding/steps/4', $vehicle)->assertOk()
        ->assertJsonPath('data.payload.vehicle.make_id', null)
        ->assertJsonPath('data.payload.vehicle.other_make', 'Other')
        ->assertJsonPath('data.payload.vehicle.model_id', null)
        ->assertJsonPath('data.payload.vehicle.other_model', 'Other');
});

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

    $country = Country::create(['name' => 'Sri Lanka', 'code' => 'LK']);
    $otherCountry = Country::create(['name' => 'India', 'code' => 'IN']);
    $state = State::create(['country_id' => $country->id, 'name' => 'Western Province']);
    $otherState = State::create(['country_id' => $otherCountry->id, 'name' => 'Tamil Nadu']);

    $this->getJson('/api/driver/onboarding/countries')->assertOk()
        ->assertJsonFragment(['id' => $country->id, 'name' => 'Sri Lanka']);
    $this->getJson("/api/driver/onboarding/countries/{$country->id}/states")->assertOk()
        ->assertJsonFragment(['id' => $state->id, 'country_id' => $country->id])
        ->assertJsonMissing(['id' => $otherState->id]);
    $this->withToken($token)->patchJson('/api/driver/onboarding/steps/3', [
        'address' => '10 Main Street', 'country_id' => $country->id, 'state_id' => $otherState->id, 'city' => 'Colombo',
    ])->assertUnprocessable()->assertJsonValidationErrors('state_id');
    $this->withToken($token)->patchJson('/api/driver/onboarding/steps/3', [
        'address' => '10 Main Street', 'country_id' => $country->id, 'state_id' => $state->id, 'city' => 'Colombo',
    ])->assertOk()->assertJsonPath('data.payload.address.country_id', $country->id)
        ->assertJsonPath('data.payload.address.state_id', $state->id);

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

it('creates an approved vehicle without automatically assigning a vehicle group', function (): void {
    $reviewer = User::create(['first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin@example.com',
        'phone' => '+94770000001', 'password' => bcrypt('Password1!'), 'is_active' => true]);
    $driverUser = User::create(['first_name' => 'Nimal', 'last_name' => 'Perera', 'email' => 'driver@example.com',
        'phone' => '+94770000002', 'password' => bcrypt('Password1!'), 'is_active' => true]);
    $make = VehicleMake::create(['name' => 'Toyota']);
    $model = VehicleModel::create(['make_id' => $make->id, 'name' => 'Axio']);
    $country = Country::create(['name' => 'Sri Lanka', 'code' => 'LK']);
    $state = State::create(['country_id' => $country->id, 'name' => 'Western Province']);
    $application = DriverOnboardingApplication::create([
        'user_id' => $driverUser->id, 'mobile' => $driverUser->phone, 'access_token_hash' => hash('sha256', 'token'),
        'mobile_verified_at' => now(), 'status' => 'submitted', 'payload' => [
            'identity' => ['first_name' => 'Nimal', 'last_name' => 'Perera', 'email' => 'driver@example.com', 'nic' => '901234567V', 'dob' => '1990-05-02'],
            'address' => ['address' => 'Colombo', 'country_id' => $country->id, 'state_id' => $state->id, 'city' => 'Colombo', 'postal_code' => '00100'],
            'vehicle' => ['make_id' => $make->id, 'model_id' => $model->id, 'model_year' => 2024, 'color' => 'White',
                'registration_year' => 2024, 'license_plate' => 'CAB-1234', 'is_owner' => true],
        ],
    ]);

    $method = new ReflectionMethod(OnboardingController::class, 'approve');
    $method->invoke(app(OnboardingController::class), $application, $reviewer->id);

    $application->refresh();
    $vehicle = Vehicle::withoutGlobalScopes()->findOrFail($application->vehicle_id);
    $driver = Driver::withoutGlobalScopes()->findOrFail($application->driver_id);
    expect($vehicle->vehicle_group_id)->toBeNull()
        ->and($vehicle->getAttributes())->not->toHaveKeys(['make_id', 'model_id'])
        ->and($driver->country_id)->toBe($country->id)
        ->and($driver->state_id)->toBe($state->id);
});
