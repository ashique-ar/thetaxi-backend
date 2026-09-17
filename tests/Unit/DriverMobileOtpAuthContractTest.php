<?php

use App\Services\Driver\DriverAuthService;

it('keeps mobile otp as the combined driver login and registration decision point', function (): void {
    $root = dirname(__DIR__, 2);
    $controller = file_get_contents($root.'/app/Http/Controllers/Api/Driver/Mobile/OnboardingController.php');
    $service = file_get_contents($root.'/app/Services/Driver/DriverAuthService.php');
    $docs = json_decode(file_get_contents($root.'/public/docs/driver-mobile-api.openapi.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($controller)
        ->toContain("'flow' => 'login'")
        ->toContain("'flow' => 'registration'")
        ->toContain('loginWithOtp($user, $data)')
        ->and($service)->toContain('public function loginWithOtp(User $user, array $credentials): array')
        ->and(data_get($docs, 'paths./api/driver/onboarding/verify-otp.post.responses.200'))->toBeArray()
        ->and(data_get($docs, 'paths./api/driver/onboarding/verify-otp.post.responses.201'))->toBeArray();
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
        ->and(data_get($docs, 'paths./api/driver/onboarding/verify-otp.post.requestBody.content.application/json.schema.properties.device_uuid'))->toBeArray()
        ->and(data_get($docs, 'paths./api/driver/onboarding/documents.post.requestBody.content.multipart/form-data.schema.properties.file.format'))->toBe('binary');
});
