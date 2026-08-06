<?php

namespace Tests\Unit;

use App\Models\Vehicle\VehicleAddon;
use App\Services\CartService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class CheckoutAddonEligibilityContractTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_global_and_matching_service_addons_are_eligible_for_the_cart_item(): void
    {
        Carbon::setTestNow('2026-07-27 12:00:00');
        $serviceItem = ['service_type_data' => ['id' => 'service-a']];

        $this->assertTrue($this->service()->eligible($this->addon(), $serviceItem));
        $this->assertTrue($this->service()->eligible($this->addon([
            'valid_from' => '2026-07-27',
            'valid_to' => '2026-07-27',
        ]), $serviceItem));
        $this->assertTrue($this->service()->eligible(
            $this->addon(['service_type_id' => 'service-a']),
            $serviceItem
        ));
    }

    public function test_other_service_inactive_and_out_of_window_addons_are_rejected(): void
    {
        Carbon::setTestNow('2026-07-27 12:00:00');
        $serviceItem = ['service_type_data' => ['id' => 'service-a']];

        $this->assertFalse($this->service()->eligible(
            $this->addon(['service_type_id' => 'service-b']),
            $serviceItem
        ));
        $this->assertFalse($this->service()->eligible(
            $this->addon(['is_active' => false]),
            $serviceItem
        ));
        $this->assertFalse($this->service()->eligible(
            $this->addon(['valid_from' => '2026-07-28']),
            $serviceItem
        ));
        $this->assertFalse($this->service()->eligible(
            $this->addon(['valid_to' => '2026-07-26']),
            $serviceItem
        ));
    }

    public function test_portal_quantity_bounds_are_enforced_on_checkout_mutations(): void
    {
        $addon = $this->addon([
            'min_qty' => 2,
            'max_qty' => 4,
        ]);

        $this->assertFalse($this->service()->quantityAllowed($addon, 1));
        $this->assertTrue($this->service()->quantityAllowed($addon, 2));
        $this->assertTrue($this->service()->quantityAllowed($addon, 4));
        $this->assertFalse($this->service()->quantityAllowed($addon, 5));
    }

    public function test_listing_and_portal_editor_share_the_same_availability_contract(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $cartService = file_get_contents($projectRoot . '/app/Services/CartService.php');
        $portalForm = file_get_contents(
            dirname($projectRoot)
                . '/portal-thetaxi/src/app/modules/addon/components/addon-form/addon-form.component.ts'
        );

        $this->assertStringContainsString('VehicleAddon::query()->available()', $cartService);
        $this->assertStringContainsString('$query->forServiceType($serviceTypeId)', $cartService);
        $this->assertStringContainsString('service_type_id: [null]', $portalForm);
        $this->assertStringContainsString('valid_from: [null]', $portalForm);
        $this->assertStringContainsString('valid_to: [null]', $portalForm);
        $this->assertStringContainsString('is_active: [true]', $portalForm);
    }

    private function addon(array $attributes = []): VehicleAddon
    {
        $addon = (new \ReflectionClass(VehicleAddon::class))->newInstanceWithoutConstructor();
        $addon->setRawAttributes(array_merge([
            'name' => 'Child Seat',
            'amount' => 1000,
            'rate_type' => 'flat',
            'is_active' => true,
            'min_qty' => 1,
            'max_qty' => null,
        ], $attributes));

        return $addon;
    }

    private function service(): object
    {
        return new class extends CartService {
            public function __construct()
            {
            }

            public function eligible(VehicleAddon $addon, array $item): bool
            {
                return $this->isAddonEligibleForItem($addon, $item);
            }

            public function quantityAllowed(VehicleAddon $addon, int $quantity): bool
            {
                return $this->isAddonQuantityAllowed($addon, $quantity);
            }
        };
    }
}
