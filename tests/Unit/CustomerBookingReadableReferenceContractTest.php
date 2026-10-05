<?php

it('renders customer booking history with a readable booking number instead of its UUID', function () {
    $types = file_get_contents(base_path('../portal-thetaxi/src/app/modules/customer/models/customer.types.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/customer/components/customer-detail/customer-detail.component.html'));
    $booking = file_get_contents(base_path('app/Models/Booking/Booking.php'));
    $controller = file_get_contents(base_path('app/Http/Controllers/Api/CustomerController.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/customer/components/customer-detail/customer-detail.component.ts'));
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/customer/services/customer.service.ts'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($types)->toContain('booking_number?: string | null;');
    expect($template)
        ->toContain('Booking reference', 'booking.booking_number')
        ->not->toContain('Booking ID', 'booking.booking_id');
    expect($booking)->toContain("'booking_number'");
    expect($controller)->toContain("'booking_number', 'booking_date', 'total_actual', 'total_estimated', 'status'");
    expect($component)->toContain('getCustomerBookingHistory(customerId', 'result?.data.bookings', 'bookingHistoryTotal');
    expect($template)->toContain('mat-paginator', 'loadCustomerHistory($event.pageIndex)');
    expect($service)->toContain('bookings: CustomerBookingHistory[]');
    expect($routes)->toContain("customers/{customer}/bookings', [CustomerController::class, 'getCustomerBookings'])->middleware('permission:customers.bookings'");
});
