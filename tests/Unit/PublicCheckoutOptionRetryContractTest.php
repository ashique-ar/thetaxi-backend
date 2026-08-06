<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PublicCheckoutOptionRetryContractTest extends TestCase
{
    public function test_addon_availability_and_modal_failures_offer_recovery(): void
    {
        $checkout = $this->checkoutSource();

        $this->assertStringContainsString(
            'function loadCheckoutAddonAvailability(wrapper)',
            $checkout
        );
        $this->assertStringContainsString('checkout-addon-availability-retry', $checkout);
        $this->assertStringContainsString('checkout-addon-load-retry', $checkout);
        $this->assertStringContainsString(
            "loadCheckoutAddonAvailability(\$(this).closest('.checkout-item-addons'));",
            $checkout
        );
    }

    public function test_extra_km_failure_stays_visible_and_can_be_retried(): void
    {
        $checkout = $this->checkoutSource();

        $this->assertStringContainsString(
            "wrapper.show().removeClass('checkout-option-pending');",
            $checkout
        );
        $this->assertStringContainsString('checkout-extra-km-load-retry', $checkout);
        $this->assertStringContainsString(
            "loadCheckoutExtraKm(\$(this).data('cart-key'), true);",
            $checkout
        );
    }

    public function test_portal_managers_and_public_retry_use_the_same_option_contracts(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $portalAddonService = file_get_contents(
            dirname($projectRoot) . '/portal-thetaxi/src/app/modules/addon/services/addon.service.ts'
        );
        $portalPricingDefinition = file_get_contents(
            dirname($projectRoot)
                . '/portal-thetaxi/src/app/modules/vehicle/components/vehicle-pricing/services/calculation-definition.service.ts'
        );
        $checkout = $this->checkoutSource();

        $this->assertStringContainsString("'/vehicles/vehicle-addons'", $portalAddonService);
        $this->assertStringContainsString('extra_km', $portalPricingDefinition);
        $this->assertStringContainsString("route('cart.addons.available')", $checkout);
        $this->assertStringContainsString("url('/cart/extra-km')", $checkout);
    }

    private function checkoutSource(): string
    {
        return file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/checkout.blade.php'
        );
    }
}
