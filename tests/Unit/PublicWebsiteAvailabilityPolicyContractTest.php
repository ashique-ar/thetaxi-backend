<?php

namespace Tests\Unit;

use App\Services\BookingFlowService;
use App\Services\WebsiteSettingsService;
use Mockery;
use Tests\TestCase;

class PublicWebsiteAvailabilityPolicyContractTest extends TestCase
{
    public function test_setting_defaults_to_enforced_and_accepts_admin_opt_out(): void
    {
        $settings = Mockery::mock(WebsiteSettingsService::class);
        $settings->shouldReceive('get')
            ->once()
            ->with('public_booking_enforce_vehicle_availability', true)
            ->andReturn(null);
        $this->app->instance(WebsiteSettingsService::class, $settings);

        $flow = $this->getMockBuilder(BookingFlowService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        self::assertTrue($flow->publicWebsiteAvailabilityEnforced());

        $settings = Mockery::mock(WebsiteSettingsService::class);
        $settings->shouldReceive('get')->once()->andReturn('false');
        $this->app->instance(WebsiteSettingsService::class, $settings);

        self::assertFalse($flow->publicWebsiteAvailabilityEnforced());
    }

    public function test_search_cart_checkout_cards_and_admin_share_one_setting(): void
    {
        $root = dirname(__DIR__, 2);
        $flow = file_get_contents($root . '/app/Services/BookingFlowService.php');
        $settings = file_get_contents($root . '/app/Services/WebsiteSettingsService.php');
        $cart = file_get_contents($root . '/app/Http/Controllers/CartController.php');
        $checkout = file_get_contents($root . '/app/Http/Controllers/CheckoutController.php');
        $card = file_get_contents($root . '/resources/views/components/vehicle-card.blade.php');
        $portal = file_get_contents($root . '/../portal-thetaxi/src/app/modules/admin/system/settings/website-settings-enhanced.component.ts');
        $portalView = file_get_contents($root . '/../portal-thetaxi/src/app/modules/admin/system/settings/website-settings-enhanced.component.html');

        self::assertStringContainsString("'public_booking_enforce_vehicle_availability'", $settings);
        self::assertStringContainsString('$bypassPublicAvailability = $isPublic && !$this->publicWebsiteAvailabilityEnforced();', $flow);
        self::assertStringNotContainsString("// public: hide groups without available vehicles", $flow);
        self::assertStringContainsString("'availability_enforced' => !\$bypassPublicAvailability", $flow);
        self::assertStringContainsString('->getPublicVehicleGroupAvailability($pricingParams)', $cart);
        self::assertStringContainsString("\$paymentType !== 'quotation'", $checkout);
        self::assertStringContainsString("get('public_booking_enforce_vehicle_availability', true)", $card);
        self::assertStringContainsString('public_booking_enforce_vehicle_availability: [true]', $portal);
        self::assertStringContainsString('formControlName="public_booking_enforce_vehicle_availability"', $portalView);
    }
}
