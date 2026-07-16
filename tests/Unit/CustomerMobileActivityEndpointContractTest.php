<?php

use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('keeps customer activity authenticated, ownership scoped, and free of pricing inputs', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Booking/CustomerMobileActivityController.php'));
    $lifecycle = file_get_contents(app_path('Services/BookingLifecycleService.php'));
    $activityService = file_get_contents(app_path('Services/CustomerMobileActivityService.php'));

    expect($routes)
        ->toContain("Route::middleware(['auth:api'])->group(function () {")
        ->toContain("'customer-mobile/bookings/{bookingId}/items/{bookingItemId}/activity'")
        ->toContain("[CustomerMobileActivityController::class, 'store']")
        ->and($controller)
        ->toContain("->whereHas('customer'")
        ->toContain("->whereKey(\$bookingItemId)")
        ->and($lifecycle)
        ->toContain('->lockForUpdate()')
        ->toContain('summaryForItem(')
        ->toContain("(\$persistedCustomerTelemetry['complete'] ?? false) === true")
        ->toContain("'source_selection' => \$sourceSelection")
        ->and($activityService)
        ->toContain('->lockForUpdate()')
        ->toContain('$persistedBooking = Booking::query()')
        ->toContain('$this->assertOwnership($persistedBooking, $lockedItem, $user)');

    $validation = Str::between($controller, '$validated = $request->validate([', ']);');
    expect($validation)
        ->toContain("'distance_km'")
        ->toContain("'waiting_minutes'")
        ->not->toContain("'charges'")
        ->not->toContain("'rate'")
        ->not->toContain("'amount'");
});
