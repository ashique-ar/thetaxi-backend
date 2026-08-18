<?php

it('filters active hires on the server before booking pagination', function () {
    $portalService = file_get_contents(
        __DIR__ . '/../../../portal-thetaxi/src/app/modules/booking/services/ongoing-hire.service.ts'
    );

    expect($portalService)
        ->toContain("operations_queue: 'active'")
        ->toContain("per_page: 100");
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
