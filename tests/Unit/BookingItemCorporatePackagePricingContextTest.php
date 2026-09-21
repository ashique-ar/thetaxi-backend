<?php

use Illuminate\Support\Str;

it('preserves corporate and package context in booking item pricing', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Services/BookingFlowService.php');
    $saveItems = Str::between($source, '// Calculate pricing for this item', '$itemPricing = $this->calculatePricing($itemPricingParams);');
    $previewItems = Str::between($source, 'private function calculateBookingItemsPricing(', 'private function calculateSingleGroupPricing(');
    $dynamicPricing = Str::between($source, 'public function calculateDynamicPricing(', '// Resolve Service Package information');

    expect($saveItems)
        ->toContain("'corporate_account_id' => \$params['corporate_account_id'] ?? null")
        ->toContain("'is_corporate_booking' => \$params['is_corporate_booking'] ?? false")
        ->and($previewItems)
        ->toContain("'service_package_id' => \$item['service_package_id']")
        ->toContain("?? \$itemMetadata['service_package_id']")
        ->toContain("'slab_definition_id' => \$item['slab_definition_id']")
        ->toContain("?? \$itemMetadata['slab_definition_id']")
        ->and($dynamicPricing)
        ->toContain("\$calculationInputs['package_id'] ?? \$calculationInputs['service_package_id'] ?? null");
});
