<?php

it('projects and searches corporate and selected employee identity in the canonical booking list', function () {
    $source = file_get_contents(app_path('Services/BookingFlowService.php'));
    $mapper = Str::between($source, 'private function mapBookingListItem(', 'private function resolveBookingListSource(');
    $query = Str::between($source, 'public function getFilteredBookings(', 'private function normalizeFilterValues(');
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/booking-list/booking-list.component.html'));

    expect($query)
        ->toContain("'booking.employeeUser:id,first_name,last_name,email,phone'")
        ->toContain("->orWhereHas('corporateAccount'")
        ->toContain("->orWhereExists(function (\$employeeUserQuery)")
        ->toContain("users.id::text = bookings.employee_id")
        ->toContain("where('booking_number', 'like'")
        ->not->toContain("where('booking_items.id', 'like'")
        ->not->toContain("orWhere('booking_items.booking_id', 'like'")
        ->and($mapper)
        ->toContain("if (\$bookingSource === 'corporate')")
        ->toContain("\$booking?->corporateAccount?->name")
        ->toContain("'corporate' => \$bookingSource === 'corporate'")
        ->toContain("'employee' => \$bookingSource === 'corporate' && \$employeeUser")
        ->toContain("'email' => \$employeeUser?->email")
        ->toContain("'phone' => \$employeeUser?->phone");

    expect($source)
        ->toContain("filled(\$booking->corporate_account_id)");

    expect($template)
        ->toContain('placeholder="Booking number, invoice, customer, reference..."')
        ->not->toContain('Booking ID');
});
