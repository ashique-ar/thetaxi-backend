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
                '$currencyCode = getBookingDisplayCurrency($booking);',
                $contents,
                $template . ' must use the booking snapshot currency for every displayed amount.'
            );
        }
    }

    public function test_booking_display_currency_prefers_the_checkout_snapshot(): void
    {
        require_once __DIR__ . '/../../app/Helpers/CurrencyHelpers.php';

        $previousContainer = \Illuminate\Container\Container::getInstance();
        $container = new \Illuminate\Container\Container();
        $container->instance(\App\Services\CurrencyService::class, new class extends \App\Services\CurrencyService {
            public function isValidCurrency(string $currencyCode): bool
            {
                return in_array($currencyCode, ['LKR', 'USD', 'EUR'], true);
            }

            public function getDefaultCurrency(): string
            {
                return 'LKR';
            }
        });
        \Illuminate\Container\Container::setInstance($container);

        try {
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
        } finally {
            \Illuminate\Container\Container::setInstance($previousContainer);
        }
    }

    public function test_success_and_email_components_receive_explicit_persisted_currency(): void
    {
        foreach ([
            __DIR__ . '/../../resources/views/checkout/success.blade.php',
            __DIR__ . '/../../resources/views/emails/checkout-confirmation.blade.php',
            __DIR__ . '/../../resources/views/emails/payment-initiated.blade.php',
            __DIR__ . '/../../resources/views/emails/quotation-request.blade.php',
        ] as $template) {
            $contents = file_get_contents($template);

            $this->assertStringContainsString(
                ':currency="$currencyCode"',
                $contents,
                basename($template) . ' must pass the currency prop through to the payment summary.'
            );
        }

        $paymentSummary = file_get_contents(__DIR__ . '/../../resources/views/components/booking-payment-summary.blade.php');
        $bookingItem = file_get_contents(__DIR__ . '/../../resources/views/components/booking-item-email.blade.php');

        $this->assertStringContainsString("'currency' => 'LKR'", $paymentSummary);
        $this->assertStringContainsString("'currency' => 'LKR'", $bookingItem);

        $controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/CheckoutController.php');
        $this->assertStringContainsString('$request->query(\'initial_currency\', \'\')', $controller);
        $this->assertStringContainsString(
            'if ($initialCurrency === $bookingCurrency)',
            $controller,
            'The success page must seed the booking currency once without overriding later visitor selections.'
        );

        $successPage = file_get_contents(__DIR__ . '/../../resources/views/checkout/success.blade.php');
        $this->assertStringContainsString("url.searchParams.delete('initial_currency')", $successPage);
        $this->assertStringContainsString('$currencyCode = getSelectedCurrency();', $successPage);
        $this->assertStringContainsString('->convert(', $successPage);
        $this->assertSame(4, substr_count($controller, '\'initial_currency\' => getBookingDisplayCurrency($booking)'));
        $this->assertStringContainsString("'source_currency' => null", $paymentSummary);
        $this->assertStringContainsString("'source_currency' => null", $bookingItem);
        $this->assertStringContainsString('$displayCurrencyCode = strtoupper(trim((string) $currency));', $bookingItem);
        $this->assertStringContainsString('$sourceCurrencyCode = $source_currency ? strtoupper(trim((string) $source_currency)) : null;', $bookingItem);
        $this->assertStringContainsString('$currencyService->convert((float) $amount, $sourceCurrencyCode, $displayCurrencyCode)', $bookingItem);
        $this->assertStringNotContainsString('->convert((float) $amount, $source_currency, $currency)', $bookingItem);
    }
}
