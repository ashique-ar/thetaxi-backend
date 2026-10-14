<?php

namespace Tests\Unit;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Service\ServiceType;
use App\Services\CurrencyService;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class BookingDistancePresentationTest extends TestCase
{
    public function test_day_services_hide_minimum_and_actual_distance_across_checkout_and_confirmation(): void
    {
        foreach (['day_rental', 'self_drive', 'with_driver', 'custom_rental'] as $code) {
            foreach ([1, 3] as $days) {
                foreach ($this->renderItems($code, 'day', 0, $days) as $html) {
                    $this->assertStringNotContainsString('Minimum', $html);
                    $this->assertStringNotContainsString('Actual', $html);
                    $this->assertStringContainsString('km', $html);
                }
            }
        }
    }

    public function test_trip_services_keep_the_minimum_and_verified_actual_distance(): void
    {
        foreach (['ride_now', 'airport_transfers', 'point_to_point'] as $code) {
            foreach ($this->renderItems($code, 'trip', 7.5) as $html) {
                $this->assertStringContainsString('Minimum', $html);
                $this->assertStringContainsString('Actual', $html);
                $this->assertStringContainsString('7.5', $html);
            }
        }
    }

    public function test_unknown_actual_distance_is_not_formatted_as_zero(): void
    {
        foreach ($this->renderItems('point_to_point', 'trip', null) as $html) {
            $this->assertStringContainsString('Minimum', $html);
            $this->assertStringNotContainsString('Actual', $html);
        }
    }

    public function test_configured_mode_takes_precedence_over_legacy_service_codes(): void
    {
        $this->assertTrue(isServiceDurationBased('self_drive'));
        $this->assertTrue(isServiceDurationBased('with_driver'));
        $this->assertTrue(isServiceDurationBased('day_rental'));
        $this->assertFalse(isServiceDurationBased('corporate', 'hour'));
        $this->assertTrue(isServiceDurationBased('custom_rental', 'day'));
        foreach ($this->renderItems('self_drive', null, 0) as $html) {
            $this->assertStringNotContainsString('Minimum', $html);
            $this->assertStringNotContainsString('Actual', $html);
        }
        foreach ($this->renderItems('day_rental', 'trip', 7.5) as $html) {
            $this->assertStringContainsString('Minimum', $html);
            $this->assertStringContainsString('7.5', $html);
        }
    }

    public function test_cart_search_and_confirmation_consumers_use_the_service_check(): void
    {
        $checkout = file_get_contents(resource_path('views/checkout.blade.php'));
        $itemView = file_get_contents(resource_path('views/checkout/partials/cart-item.blade.php'));
        $this->assertStringContainsString('assets/css/checkout-item-summary.css', $checkout);
        $summaryStyles = file_get_contents(public_path('assets/css/checkout-item-summary.css'));
        $this->assertStringContainsString('body[class] .checkout-page', $summaryStyles);
        $this->assertStringNotContainsString('body.theme-theme-04', $summaryStyles);
        $this->assertStringContainsString('body.theme-default .checkout-page .checkout-item-addons', $summaryStyles);
        $this->assertStringContainsString('container-type: inline-size', $summaryStyles);
        $this->assertStringNotContainsString('vw', $summaryStyles);
        $this->assertStringContainsString('class="checkout-item-route-details"', $itemView);
        $this->assertStringContainsString('class="checkout-item-toolbar"', $itemView);
        $this->assertStringContainsString("item.find('> .checkout-item-toolbar > .checkout-item-toggle')", $checkout);
        $this->assertStringContainsString(':not(.trip-meta)', $summaryStyles);
        $this->assertStringContainsString('.checkout-cart-item.is-collapsed .checkout-item-route-details { display: block', $summaryStyles);
        $this->assertStringContainsString('.checkout-cart-item.is-collapsed .item-total { grid-column: 2; grid-row: 1; display: block', $summaryStyles);
        foreach (['cart', 'search'] as $view) {
            $this->assertStringContainsString('@if (!isServiceDurationBased(', file_get_contents(resource_path("views/{$view}.blade.php")));
        }
        $searchView = file_get_contents(resource_path('views/search.blade.php'));
        $this->assertStringNotContainsString('$search->serviceType', $searchView);
        $this->assertStringContainsString("data_get(\$search, 'pricing_mode')", $searchView);
        $this->assertStringContainsString("'pricing_mode' => \$serviceType?->pricing_mode", file_get_contents(app_path('Http/Controllers/BookingController.php')));
        foreach (['checkout/success', 'emails/checkout-confirmation', 'emails/payment-initiated', 'emails/quotation-request'] as $view) {
            $this->assertStringContainsString('<x-booking-item-email', file_get_contents(resource_path("views/{$view}.blade.php")));
        }
    }

    private function renderItems(string $code, ?string $mode, ?float $actual, int $days = 1): array
    {
        // Render item views without the database-backed site-wide composers.
        $this->app['events']->forget('composing: *');
        $this->mock(CurrencyService::class, function ($mock) {
            $mock->shouldReceive('isValidCurrency')->with('LKR')->andReturn(true);
        });
        $distance = [
            'minimum_km' => 20,
            'minimum_km_applied' => true,
            'actual_journey_distance' => $actual,
            'journey_distance' => 20,
            'free_km_per_day' => 100,
            'allowed_total_km' => 100 * $days,
        ];
        $cartItem = [
            'name' => 'Test vehicle',
            'service_type' => $code,
            'service_type_data' => ['name' => $code, 'pricing_mode' => $mode],
            'pickup_date' => '2026-10-14',
            'return_date' => '2026-10-' . (13 + $days),
            'price' => 5000,
            'total_price' => 5000 * $days,
            'distance_details' => $distance,
        ];
        $checkout = Blade::render(
            "@foreach (\$cart as \$key => \$item) @include('checkout.partials.cart-item') @endforeach",
            ['cart' => [$cartItem], 'currencySymbol' => 'LKR'],
        );

        $item = new BookingItem([
            'duration_days' => $days,
            'unit_price' => 5000,
            'total_price' => 5000 * $days,
            'metadata' => ['distance_details' => $distance],
        ]);
        $item->setRelation('serviceType', new ServiceType(['code' => $code, 'name' => $code, 'pricing_mode' => $mode]));
        $item->setRelation('vehicleGroup', null);
        $item->setRelation('booking', new Booking(['workflow_data' => []]));
        $confirmation = Blade::render('<x-booking-item-email :item="$item" :index="0" />', ['item' => $item]);

        return [$checkout, $confirmation];
    }
}
