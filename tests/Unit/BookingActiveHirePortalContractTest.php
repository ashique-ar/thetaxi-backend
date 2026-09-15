<?php

it('filters active hires on the server before booking pagination', function () {
    $portalService = file_get_contents(
        __DIR__ . '/../../../portal-thetaxi/src/app/modules/booking/services/ongoing-hire.service.ts'
    );

    expect($portalService)
        ->toContain("operations_queue: 'active'")
        ->toContain('page: filters.page || 1')
        ->toContain('per_page: filters.per_page || 25')
        ->toContain('response?.data?.pagination');
});

it('defines active hires as paid, non-overdue trips currently in progress', function () {
    $flow = file_get_contents(__DIR__ . '/../../app/Services/BookingFlowService.php');
    $observability = file_get_contents(__DIR__ . '/../../app/Services/BookingObservabilityService.php');

    foreach ([$flow, $observability] as $source) {
        expect($source)
            ->toContain("->where('trip_phase', 'in_progress')")
            ->toContain("->where('payment_status', 'paid')")
            ->toContain("COALESCE(booking_items.to_time, '23:59:59')::time");
    }
});

it('offers one-map and list views for the active hire screen', function () {
    $component = file_get_contents(__DIR__ . '/../../../portal-thetaxi/src/app/modules/booking/components/ongoing-hire-management/ongoing-hire-management.component.ts');
    $template = file_get_contents(__DIR__ . '/../../../portal-thetaxi/src/app/modules/booking/components/ongoing-hire-management/ongoing-hire-management.component.html');

    expect($component)
        ->toContain("viewMode = signal<'list' | 'map'>('list')")
        ->toContain('getActiveTrips(200)')
        ->toContain('new google.maps.Marker');
    expect($template)
        ->toContain("setViewMode('map')")
        ->toContain('#activeHiresMap');
});

it('captures driver location from acceptance through completion in both activity timelines', function () {
    $mobile = file_get_contents(__DIR__ . '/../../../driver-mobile-app/lib/modules/taxi_app/data/remote/hire_api_service.dart');
    $acceptUi = file_get_contents(__DIR__ . '/../../../driver-mobile-app/lib/modules/taxi_app/presentation/widgets/hire_assignment_dialog.dart');
    $request = file_get_contents(__DIR__ . '/../../app/Http/Requests/Driver/Mobile/AcceptAssignmentRequest.php');
    $service = file_get_contents(__DIR__ . '/../../app/Services/Driver/MobileAssignmentService.php');
    $bookingTrace = file_get_contents(__DIR__ . '/../../app/Services/BookingObservabilityService.php');
    $driverActivity = file_get_contents(__DIR__ . '/../../app/Http/Controllers/Api/Driver/DriverController.php');

    expect($mobile)->toContain("'latitude': latitude")->toContain("'longitude': longitude");
    expect($acceptUi)->toContain('PresenceLocationResolver().resolve()');
    expect($request)->toContain("'latitude' => ['nullable', 'required_with:longitude'");
    expect($service)->toContain("'accept_latitude' => \$location['latitude'] ?? null")
        ->toContain('updateLocation($driver');
    expect($bookingTrace)->toContain("'assignment_confirmed' => \$assignment->accept_latitude");
    expect($driverActivity)->toContain("'latitude' => \$assignment->accept_latitude")
        ->toContain("'latitude' => \$assignment->final_latitude");
});

it('projects the persisted acceptance location onto the booking management map', function () {
    $assignmentController = file_get_contents(__DIR__ . '/../../app/Http/Controllers/Api/AssignmentController.php');
    $bookingManagement = file_get_contents(__DIR__ . '/../../../portal-thetaxi/src/app/modules/booking/components/booking-management/booking-management.component.ts');

    expect($assignmentController)
        ->toContain('isValidCoordinate($tripAssignment->accept_latitude, $tripAssignment->accept_longitude)')
        ->toContain("'source' => 'assignment_acceptance'");
    expect($bookingManagement)
        ->toContain("reference?.accept?.latitude")
        ->toContain("new google.maps.Marker")
        ->toContain("'A'");
});

it('explains every booking map marker and provides quick location buttons', function () {
    $component = file_get_contents(__DIR__ . '/../../../portal-thetaxi/src/app/modules/booking/components/booking-management/booking-management.component.ts');
    $template = file_get_contents(__DIR__ . '/../../../portal-thetaxi/src/app/modules/booking/components/booking-management/booking-management.component.html');

    expect($component)
        ->toContain('trackingQuickPoints()')
        ->toContain('jumpToTrackingPoint(')
        ->toContain("'R',");
    expect($template)
        ->toContain('Map key')
        ->toContain('Jump to a location')
        ->toContain('Driver accepted the hire')
        ->toContain('Latest driver location')
        ->toContain('(click)="jumpToTrackingPoint(point)"');
});

it('connects the active hire paginator to server page parameters', function () {
    $component = file_get_contents(
        __DIR__ . '/../../../portal-thetaxi/src/app/modules/booking/components/ongoing-hire-management/ongoing-hire-management.component.ts'
    );
    $template = file_get_contents(
        __DIR__ . '/../../../portal-thetaxi/src/app/modules/booking/components/ongoing-hire-management/ongoing-hire-management.component.html'
    );

    expect($component)
        ->toContain('onPageChange(event: PageEvent)')
        ->toContain('loadOngoingHires(event.pageIndex + 1, event.pageSize)');
    expect($template)
        ->toContain('<mat-paginator ui-table-pagination')
        ->toContain('[length]="pagination().total"')
        ->toContain('(page)="onPageChange($event)"');
});

it('keeps UUIDs as internal values and renders readable active-hire identifiers', function () {
    $template = file_get_contents(
        __DIR__ . '/../../../portal-thetaxi/src/app/modules/booking/components/ongoing-hire-management/ongoing-hire-management.component.html'
    );
    $backend = file_get_contents(__DIR__ . '/../../app/Services/BookingFlowService.php');

    expect($template)
        ->not->toContain('{{ hire.customer_id }}')
        ->not->toContain('{{ hire.driver_id }}')
        ->not->toContain('{{ selectedHire()?.id }}')
        ->toContain('hire.customer_code || hire.customer_phone || hire.item_code')
        ->toContain('hire.driver_code || hire.driver_license');
    $component = file_get_contents(
        __DIR__ . '/../../../portal-thetaxi/src/app/modules/booking/components/ongoing-hire-management/ongoing-hire-management.component.ts'
    );
    expect($component)
        ->not->toContain('Customer: ${value.customer_id}')
        ->not->toContain('Vehicle: ${value.vehicle_id}')
        ->not->toContain('Driver: ${value.driver_id}')
        ->not->toContain('hire-${hire.id}-report.pdf');
    expect($backend)
        ->toContain("'code' => \$customer?->code")
        ->toContain("'code' => \$itemDriver->code");
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
