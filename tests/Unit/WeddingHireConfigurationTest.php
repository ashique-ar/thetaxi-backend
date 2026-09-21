<?php

uses(Tests\TestCase::class);

it('seeds the wedding pickup choices and discounted packages through managed configuration', function (): void {
    $seeder = file_get_contents(database_path('seeders/WeddingHireFormAndPackagesSeeder.php'));
    $controller = file_get_contents(app_path('Http/Controllers/BookingController.php'));
    $bookingCss = file_get_contents(public_path('assets/css/booking-form.css'));
    $themeCss = file_get_contents(public_path('assets/css/themes/theme-04/theme-04.css'));

    expect($seeder)
        ->toContain("['CASONS_HQ', 'custom']")
        ->toContain('I need the car at my doorstep')
        ->toContain("[4 => 0.75, 8 => 1.00]")
        ->toContain("'type' => 'package_select'")
        ->toContain("'submit_as' => 'package_id'")
        ->toContain("'mobile_width' => 'full'")
        ->and($controller)
        ->toContain("where('service_type_id', \$serviceType->id)->find(\$selectedWeddingPackage)")
        ->toContain("addHours((int) (\$weddingHours ?: 8))")
        ->and($bookingCss)->toContain('.package-selector-field {')
        ->and($themeCss)->toContain('.package-button:has(input:checked) :where(.package-name, .package-detail)');
});
