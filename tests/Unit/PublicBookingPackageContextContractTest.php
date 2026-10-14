<?php

it('uses the public service type context consistently for package rendering and validation', function () {
    $basePath = dirname(__DIR__, 2);
    $requestSource = file_get_contents($basePath . '/app/Http/Requests/BookingSearchRequest.php');
    $formSource = file_get_contents($basePath . '/resources/views/components/booking-form.blade.php');
    $controllerSource = file_get_contents($basePath . '/app/Http/Controllers/BookingController.php');
    $pricingSource = file_get_contents($basePath . '/app/Services/BookingFlowService.php');
    $dynamicFormSource = file_get_contents($basePath . '/resources/views/components/dynamic-booking-form.blade.php');

    expect($requestSource)
        ->toContain('findPublicServiceType($candidateCode)')
        ->toContain("forContext('public', 'company'")
        ->toContain('->publicContext()')
        ->and($formSource)
        ->toContain('->publicContext()')
        ->toContain("->whereIn('code', \$serviceTypeCodesForConfig)")
        ->and($controllerSource)
        ->toContain('$this->resolveServiceType((string) $serviceTypeValue)')
        ->toContain("Str::isUuid(\$code) ? 'id' : 'code'")
        ->toContain("'service_type_code' => \$serviceType->code");
    expect($pricingSource)->toContain("forContext('public', 'company'")
        ->toContain('$this->resolveServiceTypeFromParams([')
        ->toContain('$pricingServiceTypeModel = $selectedServiceTypeModel')
        ->toContain('ServiceType::publicContext()')
        ->and($dynamicFormSource)->toContain('$pricingServiceType?->packages()');
});
