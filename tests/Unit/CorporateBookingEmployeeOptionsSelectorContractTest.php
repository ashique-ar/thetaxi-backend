<?php

it('uses scoped searchable CorporateEmployee options for trip stop contacts', function () {
    $base = base_path('../portal-thetaxi/src/app/modules/booking/components/booking-flow');
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Booking/Traits/BookingCorporateTrait.php'));
    $component = file_get_contents($base.'/trip-form-editor.component.ts');
    $template = file_get_contents($base.'/trip-form-editor.component.html');

    expect($routes)->toContain("Route::get('corporates/{corporateId}/employee-options', [BookingFlowController::class, 'corporateEmployeeOptions'])")
        ->toContain("permission:bookings.create")
        ->and($controller)->toContain('public function corporateEmployeeOptions(Request $request, string $corporateId)')
        ->toContain("'selected_id' => ['nullable', 'uuid']")
        ->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->toContain("'data' => $options")
        ->toContain('bool $includeInactive = false')
        ->toContain('!empty($validated[\'selected_id\'])')
        ->and($component)->toContain('corporateEmployeeOptionsEndpoint()')
        ->and(substr_count($template, '[endpoint]="corporateEmployeeOptionsEndpoint()"'))->toBe(3)
        ->toContain('clear for an external contact')
        ->not->toContain('getCorporateEmployeeOptions()');
});

it('uses the same bounded options endpoint for corporate booking travelers', function () {
    $base = base_path('../portal-thetaxi/src/app/modules/booking/components');
    $single = file_get_contents($base.'/booking-flow-single-view/booking-flow-single-view.component.html');
    $staff = file_get_contents($base.'/staff-booking-create/staff-booking-create.component.html');
    $singleComponent = file_get_contents($base.'/booking-flow-single-view/booking-flow-single-view.component.ts');
    $staffComponent = file_get_contents($base.'/staff-booking-create/staff-booking-create.component.ts');
    $selector = file_get_contents(base_path('../portal-thetaxi/src/app/shared/components/ui/managed-record-select/managed-record-select.component.ts'));

    foreach ([$single, $staff] as $template) {
        expect($template)->toContain('<app-ui-managed-record-select')
            ->toContain('[endpoint]="corporateEmployeeOptionsEndpoint()"')
            ->toContain('[disabled]="')
            ->toContain('(click)="onCreateCorporateEmployee()"');
    }

    expect($singleComponent)->toContain('corporateEmployeeOptionsEndpoint(): string')
        ->and($staffComponent)->toContain('corporateEmployeeOptionsEndpoint(): string')
        ->and($selector)->toContain('@Input() disabled = false;');
});

it('keeps corporate portal traveler and stop-contact lookup searchable and scoped', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateBookingController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $page = file_get_contents(base_path('../portal-thetaxi/src/app/modules/corporate/components/corporate-context-booking-create/corporate-context-booking-create.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/corporate/components/corporate-context-booking-create/corporate-context-booking-create.component.html'));
    $tripEditor = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/booking-flow/trip-form-editor.component.ts'));

    expect($controller)->toContain("'employee_codes' => ['sometimes', 'array', 'max:100']")
        ->toContain("whereIn('employee_code'")
        ->toContain("'selected_id' => ['nullable', 'uuid']")
        ->and($routes)->toContain("Route::get('booking-employee-options', [CorporateBookingController::class, 'bookingEmployeeOptions'])")
        ->and($page)->toContain('selectedEmployeeId')
        ->and($template)->toContain('endpoint="/corporate/booking-employee-options"')
        ->toContain('corporateEmployeeOptionsApiEndpoint="/corporate/booking-employee-options"')
        ->and($tripEditor)->toContain('getCorporateBookingEmployeeOptions')
        ->not->toContain('getBookingEmployees()');
});

it('uses managed department and dependent division selectors when creating a corporate employee', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $dialog = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/booking-flow/dialogs/corporate-employee-create-dialog.component.ts'));

    expect($routes)->toContain("Route::get('corporates/{corporateId}/department-options', [BookingFlowController::class, 'corporateDepartmentOptions'])")
        ->toContain("Route::get('corporates/{corporateId}/departments/{departmentId}/division-options', [BookingFlowController::class, 'corporateDivisionOptions'])")
        ->and($dialog)->toContain('departmentOptionsEndpoint()')
        ->toContain('divisionOptionsEndpoint()')
        ->toContain('UiManagedRecordSelectComponent')
        ->not->toContain('getCorporateDepartments(')
        ->not->toContain('getCorporateDivisions(');
});
