<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PublicCheckoutStatePreservationContractTest extends TestCase
{
    public function test_validation_redirect_restores_the_allowed_payment_type(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2) . '/app/Http/Controllers/CheckoutController.php'
        );

        $this->assertStringContainsString(
            "\$request->old('payment_type', \$request->query('type', 'full'))",
            $controller
        );
        $this->assertStringContainsString(
            'if (!in_array($paymentType, $allowedPaymentTypes, true))',
            $controller
        );
    }

    public function test_browser_back_restores_the_checkout_controls(): void
    {
        $checkout = file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/checkout.blade.php'
        );

        $this->assertStringContainsString(
            "function restoreCheckoutInteractiveState()",
            $checkout
        );
        $this->assertStringContainsString(
            "$('#checkout-submit-btn').prop('disabled', false);",
            $checkout
        );
        $this->assertStringContainsString(
            "$('input[name=\"payment_type\"]:checked').trigger('change');",
            $checkout
        );
        $this->assertStringContainsString(
            "window.addEventListener('pageshow', restoreCheckoutInteractiveState);",
            $checkout
        );
    }

    public function test_portal_setting_and_public_checkout_keep_the_same_payment_owner(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $portalSettings = file_get_contents(
            dirname($projectRoot)
                . '/portal-thetaxi/src/app/modules/admin/system/settings/website-settings-enhanced.component.ts'
        );
        $checkout = file_get_contents($projectRoot . '/resources/views/checkout.blade.php');

        $this->assertStringContainsString("'advance_payment_enabled'", $portalSettings);
        $this->assertStringContainsString('name="payment_type"', $checkout);
        $this->assertStringContainsString("old('terms_accepted', [])", $checkout);
    }

    public function test_checkout_displays_validation_failures_and_checks_the_terms_it_shows(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root . '/app/Http/Controllers/CheckoutController.php');
        $checkout = file_get_contents($root . '/resources/views/checkout.blade.php');

        $this->assertSame(2, substr_count($controller, '$this->getCheckoutServiceTerms($cart)'));
        $this->assertStringContainsString("data_get(\$item, 'service_type_data.id')", $controller);
        $this->assertStringNotContainsString("'airport_transfers' => 'vehicle_rental'", $controller);
        $this->assertStringContainsString('$errors->all()', $checkout);
        $this->assertStringContainsString("session('error')", $checkout);
        $this->assertStringContainsString("syncAcceptedTerms();\n                const \$termsPanel", str_replace("\r\n", "\n", $checkout));
    }
}
