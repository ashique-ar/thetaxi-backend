<?php

it('filters active hires on the server before booking pagination', function () {
    $portalService = file_get_contents(
        __DIR__ . '/../../../portal-thetaxi/src/app/modules/booking/services/ongoing-hire.service.ts'
    );

    expect($portalService)
        ->toContain("operations_queue: 'active'")
        ->toContain("per_page: 100");
});

it('accepts every implemented operations queue through either request parameter', function () {
    $controller = file_get_contents(
        __DIR__ . '/../../app/Http/Controllers/Api/Booking/Traits/BookingSubmissionTrait.php'
    );
    $allowedQueues = 'needs_approval,needs_assignment,ready_to_dispatch,active,return_due,qc_pending,repair_pending,ready_to_complete,payment_pending,payment_attention';

    expect($controller)
        ->toContain("'operations_queue' => 'nullable|string|in:{$allowedQueues}'")
        ->toContain("'queue' => 'nullable|string|in:{$allowedQueues}'");
});

it('force completion resolves the selected item lifecycle before rejecting the hire', function () {
    $service = file_get_contents(__DIR__ . '/../../app/Services/BookingLifecycleService.php');
    $forceCompletion = substr(
        $service,
        strpos($service, 'public function forceCompleteBooking'),
        strpos($service, 'private function closeDriverAssignmentsForCompletion')
            - strpos($service, 'public function forceCompleteBooking')
    );

    expect($forceCompletion)
        ->toContain('resolveLifecycleContext($bookingForStatus, $bookingItemId)')
        ->toContain('resolveSelectedItemLifecycleStatus(')
        ->not->toContain('$bookingForStatus->getLifecycleStatus()');
});
