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

it('applies website visibility and inquiry restrictions only to public availability', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/BookingFlowService.php');
    $availabilityMethod = Str::between($source, 'public function getAvailableVehicleGroups(', 'public function getAvailableVehiclesInGroup(');

    expect($availabilityMethod)
        ->toContain('if ($isPublic && $servicePricingSetting?->is_hidden)')
        ->toContain('if ($isPublic && $serviceTypeModel)')
        ->toContain('$isInquiryOnly = $isPublic &&')
        ->toContain('if ($isPublic && ($group->force_quotation_request ?? false))')
        ->toContain("\$quotationOnlyReasons[] = 'pricing_not_configured'")
        ->toContain("\$quotationOnlyReasons[] = 'pricing_error'");
});

it('waits for a required package selection and treats missing rates as configuration', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/BookingFlowService.php');
    $availabilityMethod = Str::between($source, 'public function getAvailableVehicleGroups(', 'public function getAvailableVehiclesInGroup(');
    $pricingMethod = Str::after($source, 'public function calculatePricing(array $params): array');

    expect($availabilityMethod)
        ->toContain('$packageSelectionRequired')
        ->toContain('$hasSelectedPackage')
        ->toContain('(!$packageSelectionRequired || $hasSelectedPackage)')
        ->toContain('catch (\DomainException $e)')
        ->and($pricingMethod)
        ->toContain("Log::notice('Pricing configuration is incomplete'");
});
