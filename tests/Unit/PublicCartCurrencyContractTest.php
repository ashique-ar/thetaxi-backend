<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicCartCurrencyContractTest extends TestCase
{
    #[Test]
    public function cart_surfaces_use_current_response_currency_without_stale_cache(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/CartController.php'));
        $float = file_get_contents(resource_path('views/components/cart-summary-float.blade.php'));
        $home = file_get_contents(resource_path('views/home.blade.php'));
        $success = file_get_contents(resource_path('views/checkout/success.blade.php'));

        $this->assertStringNotContainsString("cache()->remember(\$cartCacheKey, 30", $controller);
        $this->assertStringContainsString("'currency_symbol' => \$cartArray['currency_symbol']", $controller);
        $this->assertStringContainsString('response.currency_symbol || cartTotals.currency_symbol', $float);
        $this->assertStringContainsString("$('#cartSummaryFloat .currency-symbol').text(cartCurrencySymbol)", $float);
        $this->assertStringContainsString('response.currency_symbol || (response.totals && response.totals.currency_symbol)', $home);
        $this->assertStringNotContainsString(": '$';", $success);
    }
}
