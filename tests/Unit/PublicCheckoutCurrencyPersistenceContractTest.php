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

    public function test_cart_conversion_returns_items_as_an_associative_array(): void
    {
        $service = file_get_contents(__DIR__ . '/../../app/Services/CartService.php');

        $this->assertStringContainsString(
            "'items' => \$convertedItems->all(),",
            $service
        );
        $this->assertStringNotContainsString(
            "'items' => \$convertedItems,",
            $service
        );
    }

    public function test_checkout_emails_use_the_persisted_display_currency_snapshot(): void
    {
        $helpers = file_get_contents(__DIR__ . '/../../app/Helpers/CurrencyHelpers.php');

        $this->assertStringContainsString("\$workflowData['display_currency']", $helpers);
        $this->assertStringContainsString('function getBookingDisplayCurrency(object $booking): string', $helpers);

        foreach ([
            'payment-initiated.blade.php',
            'checkout-confirmation.blade.php',
            'quotation-request.blade.php',
        ] as $template) {
            $contents = file_get_contents(__DIR__ . '/../../resources/views/emails/' . $template);

            $this->assertStringContainsString(
                '$currencySymbol = getBookingDisplayCurrency($booking);',
                $contents,
                $template . ' must use the booking snapshot currency for every displayed amount.'
            );
        }
    }

    public function test_booking_display_currency_prefers_the_checkout_snapshot(): void
    {
        require_once __DIR__ . '/../../app/Helpers/CurrencyHelpers.php';

        $booking = (object) [
            'currency' => 'LKR',
            'workflow_data' => ['display_currency' => 'usd'],
        ];

        $this->assertSame('USD', getBookingDisplayCurrency($booking));
        $this->assertSame('EUR', getBookingDisplayCurrency((object) [
            'currency' => 'eur',
            'workflow_data' => [],
        ]));
        $this->assertSame('LKR', getBookingDisplayCurrency((object) [
            'currency' => 'invalid',
            'workflow_data' => null,
        ]));
    }
}
