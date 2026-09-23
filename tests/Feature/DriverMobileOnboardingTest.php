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
use App\Http\Resources\Driver\DriverResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

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

    Cache::put('driver_auth_otp:'.sha1('+94771234567'), Hash::make('654321'), now()->addMinutes(10));
    $this->postJson('/api/driver/auth/verify-otp', ['mobile' => '+94771234567', 'otp' => '654321'])
        ->assertCreated()
        ->assertJsonPath('data.application.id', DriverOnboardingApplication::first()->id)
        ->assertJsonPath('data.application.status', 'changes_requested');
    expect(DriverOnboardingApplication::where('mobile', '+94771234567')->count())->toBe(1);
});

it('creates an approved vehicle without automatically assigning a vehicle group', function (): void {
    Role::create(['name' => 'driver', 'guard_name' => 'api']);
    Storage::fake('s3');
    config(['filesystems.default' => 's3']);
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
    foreach (['driver_photo' => 'photo.jpg', 'vehicle_registration' => 'registration.pdf'] as $type => $fileName) {
        $path = "driver-onboarding/{$application->id}/{$fileName}";
        Storage::disk('s3')->put($path, 'test-file');
        $application->documents()->create([
            'documentable_type' => DriverOnboardingApplication::class,
            'documentable_id' => $application->id,
            'document_type' => $type,
            'document_number' => 'TEST-1',
            'disk' => 's3', 'path' => $path, 'file_name' => $fileName,
            'file_size' => 9, 'file_type' => $type === 'driver_photo' ? 'image/jpeg' : 'application/pdf',
            'status' => 'pending',
        ]);
    }

    $method = new ReflectionMethod(OnboardingController::class, 'approve');
    $method->invoke(app(OnboardingController::class), $application, $reviewer->id);

    $application->refresh();
    $vehicle = Vehicle::withoutGlobalScopes()->findOrFail($application->vehicle_id);
    $driver = Driver::withoutGlobalScopes()->findOrFail($application->driver_id);
    $driverContext = $driverUser->contexts()->where('context_type', 'driver')->firstOrFail();
    $mobilePayload = (new DriverResource($driver->load([
        'user', 'documents', 'profilePhotoDocument', 'defaultVehicle.make', 'defaultVehicle.model',
        'defaultVehicle.group.make', 'defaultVehicle.group.model', 'defaultVehicle.documents',
    ])))->toArray(request());
    $photoDocument = $driver->documents->firstWhere('document_type', 'driver_photo');
    $registrationDocument = $driver->defaultVehicle->documents->firstWhere('document_type', 'vehicle_registration');
    expect($vehicle->vehicle_group_id)->toBeNull()
        ->and($vehicle->make_id)->toBe($make->id)
        ->and($vehicle->model_id)->toBe($model->id)
        ->and($driver->country_id)->toBe($country->id)
        ->and($driver->state_id)->toBe($state->id)
        ->and($driverContext->context_id)->toBe($driver->id)
        ->and($driverContext->is_active)->toBeTrue()
        ->and($driverContext->roles()->where('name', 'driver')->exists())->toBeTrue()
        ->and($driverUser->fresh()->hasRole('driver'))->toBeTrue()
        ->and($mobilePayload['profile_photo_url'])->not->toBeNull()
        ->and($mobilePayload['profile_photo']['mime_type'])->toBe('image/jpeg')
        ->and($mobilePayload['vehicle']['license_plate'])->toBe('CAB-1234')
        ->and($mobilePayload['vehicle']['make']['name'])->toBe('Toyota')
        ->and($mobilePayload['vehicle']['model']['name'])->toBe('Axio')
        ->and($mobilePayload['vehicle']['documents'])->toHaveCount(1)
        ->and($mobilePayload['profile_photo']['resource_url'])->toContain("/resources/drivers/{$driver->id}/documents/driver_photo/")
        ->and($mobilePayload['vehicle']['documents'][0]['resource_url'])->toContain("/resources/vehicles/{$vehicle->id}/documents/vehicle_registration/")
        ->and($photoDocument->path)->toStartWith("drivers/{$driver->id}/documents/driver_photo/")
        ->and($registrationDocument->path)->toStartWith("vehicles/{$vehicle->id}/documents/vehicle_registration/")
        ->and(Storage::disk('s3')->exists($photoDocument->path))->toBeTrue()
        ->and(Storage::disk('s3')->exists($registrationDocument->path))->toBeTrue();
});

it('records the staff user who updates an onboarding application', function (): void {
    $reviewer = User::create([
        'first_name' => 'Review', 'last_name' => 'Officer', 'email' => 'reviewer@example.com',
        'phone' => '+94770000009', 'password' => bcrypt('Password1!'), 'is_active' => true,
    ]);
    $application = DriverOnboardingApplication::create([
        'mobile' => '+94771234567', 'access_token_hash' => hash('sha256', 'audit-token'),
        'mobile_verified_at' => now(), 'status' => 'submitted', 'payload' => ['identity' => ['first_name' => 'Nimal']],
    ]);

    $this->actingAs($reviewer, 'api');
    $application->update(['payload' => ['identity' => ['first_name' => 'Kamal']]]);

    expect($application->fresh()->updated_user_id)->toBe($reviewer->id);
});
