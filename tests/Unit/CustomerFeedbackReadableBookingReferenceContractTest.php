<?php

it('renders feedback booking references without exposing booking identifiers', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/CustomerController.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/customer/components/feedback/feedback-system.component.html'));

    expect($controller)->toContain("'booking_number' => 'B001'", "'booking_number' => 'B002'")
        ->and($template)->toContain('feedback.booking_number', 'feedback.booking_reference', 'reference unavailable')
        ->not->toContain('feedback.booking_id }}');
});
