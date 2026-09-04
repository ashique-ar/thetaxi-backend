<?php

use Database\Seeders\AllPermissionsSeeder;
use Illuminate\Routing\Route;

it('keeps generic payment method routes outside booking permissions', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes());

    foreach ([
        ['GET', 'api/payment-methods'],
        ['GET', 'api/payment-methods/{payment_method}'],
    ] as [$method, $uri]) {
        $route = $routes->first(fn (Route $candidate) => $candidate->uri() === $uri && in_array($method, $candidate->methods(), true));
        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('permission:payment-methods.view')
            ->not->toContain('permission:bookings.view');
    }
});

it('registers sensitive permissions without baseline sub admin expansion', function () {
    $source = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));

    foreach ([
        'staff-sensitive-documents.view',
        'staff-sensitive-documents.create',
        'staff-sensitive-documents.verify',
        'staff-sensitive-documents.delete',
        'staff-sensitive-payment-methods.view',
        'staff-sensitive-payment-methods.request',
        'staff-sensitive-payment-methods.approve',
        'staff.terminate',
    ] as $permission) {
        expect(AllPermissionsSeeder::allPermissionNames())->toContain($permission)
            ->and($source)->toContain("'{$permission}'");
    }

    expect($source)->toContain('denyByDefaultPermissions');
});

it('masks every staff payment method resource and blocks generic staff access', function () {
    $genericController = file_get_contents(app_path('Http/Controllers/Api/PaymentMethodController.php'));
    $genericResource = file_get_contents(app_path('Http/Resources/PaymentMethodResource.php'));
    $maskedResource = file_get_contents(app_path('Http/Resources/StaffPaymentMethodResource.php'));

    expect($genericController)
        ->toContain('assertNotStaffPayable')
        ->toContain("whereNotIn('payable_type', ['staff', Staff::class])")
        ->and($genericResource)->toContain('isStaffOwned()')
        ->and($maskedResource)
        ->toContain('account_number_masked')
        ->not->toContain("'account_number' => \$this->account_number");
});

it('uses maker checker for staff banking changes', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/StaffSensitivePaymentMethodController.php'));

    expect($controller)
        ->toContain("abort_if(\$locked->requested_by === \$request->user()->id")
        ->toContain("'status' => 'approved'")
        ->toContain("'status' => 'rejected'")
        ->toContain('lockForUpdate()');
});

it('keeps staff documents private and checks owner type on every direct action', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/DocumentController.php'));
    $filesystem = file_get_contents(config_path('filesystems.php'));

    expect($controller)
        ->toContain('assertDocumentAccess($request, $document')
        ->toContain("return 'hr_private'")
        ->toContain("'file_url' => \$isStaffDocument ? null")
        ->and($filesystem)->toContain("'hr_private' => [");
});

it('terminates only the staff context and retains other user contexts', function () {
    $service = file_get_contents(app_path('Services/StaffIdentityService.php'));

    expect($service)
        ->toContain("deactivateContext(\$user, 'staff')")
        ->toContain("'user_disabled' => false")
        ->toContain('revokeAllTokens()')
        ->not->toContain("\$user->update(['is_active' => false])");
});
