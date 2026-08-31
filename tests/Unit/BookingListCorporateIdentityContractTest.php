<?php

uses(Tests\TestCase::class);

it('projects and searches corporate and selected employee identity in the canonical booking list', function () {
    $source = file_get_contents(app_path('Services/BookingFlowService.php'));
    $mapper = Str::between($source, 'private function mapBookingListItem(', 'private function resolveBookingListSource(');
    $query = Str::between($source, 'public function getFilteredBookings(', 'private function normalizeFilterValues(');

    expect($query)
        ->toContain("'booking.employeeUser:id,first_name,last_name,email,phone'")
        ->toContain("->orWhereHas('corporateAccount'")
        ->toContain("->orWhereHas('employeeUser'")
        ->and($mapper)
        ->toContain("'corporate' => \$bookingSource === 'corporate'")
        ->toContain("'employee' => \$bookingSource === 'corporate' && \$employeeUser")
        ->toContain("'email' => \$employeeUser?->email")
        ->toContain("'phone' => \$employeeUser?->phone");
});
