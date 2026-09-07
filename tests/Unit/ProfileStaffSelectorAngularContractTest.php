<?php

it('uses a bounded tenant and approved-category scoped Staff selector for Profile enrollment', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesProfileController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-profile-administration/sales-profile-administration.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-profile-administration/sales-profile-administration.component.html'));

    expect($routes)->toContain("Route::get('profile-staff-options', [SalesProfileController::class, 'staffOptions'])")
        ->and($controller)->toContain('public function staffOptions(Request $request)', "'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->toContain("whereIn('staff_type', \$categories)", "whereNull('deleted_at')", "whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())")
        ->and($component)->not->toContain('staff = signal', 'eligibleStaff()')
        ->and($template)->toContain('endpoint="/sales/profile-staff-options"', '[companyId]="companyId"', 'placeholder="Search Staff name or code"')
        ->not->toContain('*ngFor="let row of eligibleStaff()"');
});
