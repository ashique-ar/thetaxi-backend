<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PublicCheckoutCurrencyPersistenceContractTest extends TestCase
{
    public function test_checkout_persists_the_converted_cart_snapshot_and_currency(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/CheckoutController.php');

        $this->assertStringContainsString(
            '$cartData = $this->cartService->toArray($cartModel);',
            $controller
        );
        $this->assertStringContainsString(
            "\$totals = \$cartData['totals'] ?? [];",
            $controller
        );
        $this->assertStringContainsString(
            "'currency' => \$bookingCurrency",
            $controller
        );
        $this->assertStringContainsString(
            "'exchange_rate' => \$this->currencyService->getExchangeRate(",
            $controller
        );
    }

    public function test_cart_conversion_includes_nested_addon_amounts(): void
    {
        $service = file_get_contents(__DIR__ . '/../../app/Services/CartService.php');

        $this->assertStringContainsString(
            "foreach (['amount', 'calculated_amount', 'total', 'unit_price', 'total_price'] as \$field)",
            $service
        );
        $this->assertStringContainsString(
            "\$addon['currency'] = \$selectedCurrency;",
            $service
        );
    }
}
