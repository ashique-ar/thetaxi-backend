<?php

use Illuminate\Support\Str;

it('forwards corporate ownership into each vehicle group card pricing calculation', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/BookingFlowService.php');
    $availabilityMethod = Str::between(
        $source,
        'public function getAvailableVehicleGroups(',
        'public function getAvailableVehiclesInGroup('
    );

    expect($availabilityMethod)
        ->toContain("'is_corporate_booking' => filter_var(")
        ->toContain("\$params['is_corporate_booking'] ?? !empty(\$corporateAccountId)")
        ->toContain("'corporate_account_id' => \$corporateAccountId")
        ->toContain('$this->calculatePricing($pricingParams)');
});
