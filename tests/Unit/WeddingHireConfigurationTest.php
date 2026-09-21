<?php

uses(Tests\TestCase::class);

it('seeds the wedding pickup choices and discounted packages through managed configuration', function (): void {
    $seeder = file_get_contents(database_path('seeders/WeddingHireFormAndPackagesSeeder.php'));
    $pricingSeeder = file_get_contents(database_path('seeders/ComprehensivePricingSeeder.php'));
    $controller = file_get_contents(app_path('Http/Controllers/BookingController.php'));
    $bookingCss = file_get_contents(public_path('assets/css/booking-form.css'));
    $themeCss = file_get_contents(public_path('assets/css/themes/theme-04/theme-04.css'));

    expect($seeder)
        ->toContain("['CASONS_HQ', 'custom']")
        ->toContain('I need the car at my doorstep')
        ->toContain("[4 => 0.75, 8 => 1.00]")
        ->toContain("'name' => '8 Hour Wedding Rate'")
        ->toContain("'min_hours' => 4")
        ->toContain("'max_hours' => 8")
        ->toContain("->whereKeyNot(\$baseRateSlab->id)")
        ->toContain("VehicleGroupCommonRatePricing::withTrashed()")
        ->toContain("'formula' => 'slab_rate'")
        ->toContain("'type' => 'package_select'")
        ->toContain("'submit_as' => 'package_id'")
        ->toContain("'mobile_width' => 'full'")
        ->and($controller)
        ->toContain("->where('is_active', true)")
        ->toContain("abort_if(!\$weddingHours, 422")
        ->toContain("addHours((int) \$weddingHours)")
        ->and($pricingSeeder)
        ->not->toContain("'formula' => 'slab_rate + decoration_charge + (extra_km * extra_km_rate) + (extra_hours * extra_hour_rate)'")
        ->and($bookingCss)->toContain('.package-selector-field {')
        ->and($themeCss)->toContain('.package-button:has(input:checked) :where(.package-name, .package-detail)');
});
