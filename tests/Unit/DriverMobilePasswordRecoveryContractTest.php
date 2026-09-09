<?php

it('exposes driver scoped password recovery routes', function () {
    $routes = file_get_contents(base_path('routes/api_driver.php'));

    expect($routes)
        ->toContain("Route::post('forgot-password'")
        ->toContain("Route::post('reset-password'")
        ->toContain("Route::post('change-password'");
});

it('keeps recovery enumeration safe and driver scoped', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Driver/Mobile/AuthController.php'));
    $service = file_get_contents(app_path('Services/Driver/DriverAuthService.php'));
    $notification = file_get_contents(app_path('Notifications/DriverPasswordResetNotification.php'));

    expect($controller)
        ->toContain('If an eligible driver account exists')
        ->not->toContain("'email' => ['required', 'string', 'email', 'max:255', 'exists:users,email']");
    expect($service)
        ->toContain('! $this->isDriver($user)')
        ->toContain("'login_attempts' => 0")
        ->toContain('$this->revokeAllTokens($user)')
        ->toContain('$this->deviceService->deactivateOtherDevices($driver)');
    expect($notification)
        ->toContain('thetaxidriver://reset-password')
        ->not->toContain("'token' => \$this->token");
});
