<?php

it('uses the public service type context consistently for package rendering and validation', function () {
    $basePath = dirname(__DIR__, 2);
    $requestSource = file_get_contents($basePath . '/app/Http/Requests/BookingSearchRequest.php');
    $formSource = file_get_contents($basePath . '/resources/views/components/booking-form.blade.php');
    $controllerSource = file_get_contents($basePath . '/app/Http/Controllers/BookingController.php');

    expect($requestSource)
        ->toContain('->publicContext()')
        ->toContain("->where('code', \$candidateCode)")
        ->and($formSource)
        ->toContain('->publicContext()')
        ->toContain("->whereIn('code', \$serviceTypeCodesForConfig)")
        ->and($controllerSource)->toContain('ServiceType::publicContext()');
});
