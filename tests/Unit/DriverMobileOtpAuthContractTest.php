<?php

use App\Services\Driver\DriverAuthService;

it('keeps mobile otp as the combined driver login and registration decision point', function (): void {
    $root = dirname(__DIR__, 2);
    $controller = file_get_contents($root.'/app/Http/Controllers/Api/Driver/Mobile/AuthController.php');
    $onboardingController = file_get_contents($root.'/app/Http/Controllers/Api/Driver/Mobile/OnboardingController.php');
    $service = file_get_contents($root.'/app/Services/Driver/DriverAuthService.php');
    $docs = json_decode(file_get_contents($root.'/public/docs/driver-mobile-api.openapi.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($controller)
        ->toContain("'flow' => 'login'")
        ->toContain("'flow' => 'registration'")
        ->toContain('loginWithOtp($user, $data)')
        ->toContain("unset(\$applicationData['payload']['identity']['dob'])")
        ->and($onboardingController)->not->toContain('function requestOtp')
        ->not->toContain('function verifyOtp')
        ->toContain("unset(\$data['payload']['identity']['dob'])")
        ->and($service)->toContain('public function loginWithOtp(User $user, array $credentials): array')
        ->and(data_get($docs, 'paths./api/driver/auth/verify-otp.post.responses.200'))->toBeArray()
        ->and(data_get($docs, 'paths./api/driver/auth/verify-otp.post.responses.201'))->toBeArray();
});

it('documents every driver API response body and the canonical onboarding payloads', function (): void {
    $docs = json_decode(file_get_contents(dirname(__DIR__, 2).'/public/docs/driver-mobile-api.openapi.json'), true, flags: JSON_THROW_ON_ERROR);
    $operations = collect($docs['paths'])->flatMap(fn (array $path, string $url) => collect($path)
        ->only(['get', 'post', 'put', 'patch', 'delete'])
        ->map(fn (array $operation, string $method) => ['url' => $url, 'method' => $method, 'operation' => $operation]));

    $missingBodies = $operations->flatMap(fn (array $item) => collect($item['operation']['responses'] ?? [])
        ->reject(fn (array $response, int|string $status) => (string) $status === '204' || isset($response['content']['application/json']))
        ->keys()->map(fn (int|string $status) => strtoupper($item['method'])." {$item['url']} {$status}"));

    expect($missingBodies)->toBeEmpty()
        ->and(data_get($docs, 'paths./api/driver/onboarding/steps/{step}.patch.parameters.0.schema.enum'))->toBe([1, 3, 4])
        ->and(data_get($docs, 'paths./api/driver/onboarding/steps/{step}.patch.requestBody.content.application/json.schema.oneOf'))->toHaveCount(3)
        ->and(data_get($docs, 'paths./api/driver/auth/verify-otp.post.requestBody.content.application/json.schema.properties.device_uuid'))->toBeArray()
        ->and(data_get($docs, 'paths./api/driver/onboarding/documents.post.requestBody.content.multipart/form-data.schema.properties.file.format'))->toBe('binary');
});

it('exposes and documents country and state lookups for driver onboarding', function (): void {
    $root = dirname(__DIR__, 2);
    $routes = file_get_contents($root.'/routes/api_driver.php');
    $docs = json_decode(file_get_contents($root.'/public/docs/driver-mobile-api.openapi.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($routes)
        ->toContain("Route::get('countries', [UtilityController::class, 'countries'])")
        ->toContain("Route::get('countries/{country}/states', [UtilityController::class, 'states'])")
        ->and(data_get($docs, 'paths./api/driver/onboarding/countries.get.responses.200'))->toBeArray()
        ->and(data_get($docs, 'paths./api/driver/onboarding/countries/{country_id}/states.get.parameters.0.name'))->toBe('country_id');
});

it('documents vehicle lookups and make model IDs for onboarding', function (): void {
    $docs = json_decode(file_get_contents(dirname(__DIR__, 2).'/public/docs/driver-mobile-api.openapi.json'), true, flags: JSON_THROW_ON_ERROR);
    $folder = collect(json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/postman/Driver-API.postman_collection.json'), true, flags: JSON_THROW_ON_ERROR)['item'])
        ->firstWhere('name', 'Driver Onboarding');
    $names = collect($folder['item'])->pluck('name');

    expect(data_get($docs, 'paths./api/driver/onboarding/makes.get.responses.200'))->toBeArray()
        ->and(data_get($docs, 'paths./api/driver/onboarding/makes/{make_id}/models.get.parameters.0.name'))->toBe('make_id')
        ->and(data_get($docs, 'paths./api/driver/onboarding/steps/{step}.patch.requestBody.content.application/json.schema.oneOf.2.required'))
            ->not->toContain('make_id', 'model_id')
        ->and(data_get($docs, 'paths./api/driver/onboarding/steps/{step}.patch.requestBody.content.application/json.schema.oneOf.2.properties.other_make'))->toBeArray()
        ->and(data_get($docs, 'paths./api/driver/onboarding/steps/{step}.patch.requestBody.content.application/json.schema.oneOf.2.properties.other_model'))->toBeArray()
        ->and($names)->toContain('List Vehicle Makes', 'List Models by Make', 'Save Vehicle Step');
});

it('documents the complete driver account projection on every authentication response', function (): void {
    $root = dirname(__DIR__, 2);
    $resource = file_get_contents($root.'/app/Http/Resources/Driver/DriverResource.php');
    $controller = file_get_contents($root.'/app/Http/Controllers/Api/Driver/Mobile/AuthController.php');
    $service = file_get_contents($root.'/app/Services/Driver/DriverAuthService.php');
    $docs = json_decode(file_get_contents($root.'/public/docs/driver-mobile-api.openapi.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($resource)
        ->toContain("'profile_image_url'")
        ->toContain("'profile_image'")
        ->toContain("'assigned_vehicle'")
        ->toContain("'vehicle'")
        ->and(substr_count($controller, 'new DriverResource('))->toBe(3)
        ->and($service)->toContain("'defaultVehicle.make'")
        ->toContain("'defaultVehicle.model'")
        ->toContain("'defaultVehicle.documents'")
        ->and(data_get($docs, 'components.schemas.DriverMobileAccount.properties.profile_image_url'))->toBeArray()
        ->and(data_get($docs, 'components.schemas.DriverMobileAccount.properties.profile_image'))->toBeArray()
        ->and(data_get($docs, 'components.schemas.DriverMobileAccount.properties.vehicle.allOf.0.$ref'))->toBe('#/components/schemas/DriverVehicle')
        ->and(data_get($docs, 'components.schemas.DriverVehicle.properties.documents.items.$ref'))->toBe('#/components/schemas/DriverMobileDocument')
        ->and(data_get($docs, 'paths./api/driver/auth/login.post.responses.200.content.application/json.schema.properties.data.properties.driver.$ref'))->toBe('#/components/schemas/DriverMobileAccount')
        ->and(data_get($docs, 'paths./api/driver/auth/verify-otp.post.responses.200.content.application/json.schema.properties.data.properties.driver.$ref'))->toBe('#/components/schemas/DriverMobileAccount')
        ->and(data_get($docs, 'paths./api/driver/auth/profile.get.responses.200.content.application/json.schema.properties.data.properties.driver.$ref'))->toBe('#/components/schemas/DriverMobileAccount');
});
