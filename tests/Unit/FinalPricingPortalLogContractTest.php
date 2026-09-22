<?php

test('pending final pricing is recorded in portal audit logs without email', function () {
    $service = file_get_contents(dirname(__DIR__, 2).'/app/Services/BookingLifecycleService.php');

    expect($service)
        ->toContain("'action' => 'final_pricing_pending_manual_review'")
        ->toContain("'entity' => 'BookingItem'")
        ->not->toContain('PricingResolutionFailedMail')
        ->not->toContain('alertOpsPricingResolutionFailed');

    expect(file_exists(dirname(__DIR__, 2).'/app/Mail/PricingResolutionFailedMail.php'))->toBeFalse();
});
