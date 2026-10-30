<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

it('keeps driver location and recovery traffic in separate rate limit buckets', function (): void {
    $request = Request::create('/api/driver/location');
    $request->setUserResolver(fn () => new class
    {
        public function getAuthIdentifier(): string
        {
            return 'driver-1';
        }
    });

    $limits = collect([
        'driver-location-live',
        'driver-location-bulk',
        'driver-location-incidents',
        'driver-diagnostic-reports',
        'driver-hire-recovery',
    ])->mapWithKeys(fn (string $name) => [
        $name => RateLimiter::limiter($name)($request),
    ]);

    expect($limits->get('driver-location-live')->maxAttempts)->toBe(60)
        ->and($limits->get('driver-location-bulk')->maxAttempts)->toBe(10)
        ->and($limits->get('driver-diagnostic-reports')->maxAttempts)->toBe(30)
        ->and($limits->get('driver-diagnostic-reports')->decaySeconds)->toBe(3600)
        ->and($limits->pluck('key')->unique())->toHaveCount(5);

    $routes = file_get_contents(base_path('routes/api_driver.php'));
    expect($routes)
        ->toContain("'throttle:driver-location-live'")
        ->toContain("'throttle:driver-location-bulk'")
        ->toContain("'throttle:driver-location-incidents'")
        ->toContain("'throttle:driver-diagnostic-reports'")
        ->toContain("'throttle:driver-hire-recovery'");
});
