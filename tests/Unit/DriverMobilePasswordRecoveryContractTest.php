<?php

it('exposes driver scoped password recovery routes', function () {
    $routes = file_get_contents(base_path('routes/api_driver.php'));

    expect($routes)
        ->toContain("Route::post('forgot-password'")
        ->toContain("Route::post('reset-password'")
        ->toContain("Route::post('change-password'");
});

it('includes the password reset token storage migration', function () {
    $migration = file_get_contents(base_path(
        'database/migrations/2026_09_10_000002_create_password_reset_tokens_table.php'
    ));

    expect($migration)
        ->toContain("Schema::create('password_reset_tokens'")
        ->toContain("\$table->string('email')->primary()")
        ->toContain("\$table->string('token')")
        ->toContain("\$table->timestamp('created_at')->nullable()");
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
